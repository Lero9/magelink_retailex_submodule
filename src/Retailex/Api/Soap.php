<?php
/**
 * Implements SOAP access to Retail Express
 * @category Retailex
 * @package Retailex\Api
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2016 LERO9 Ltd.
 * @license Commercial - All Rights Reserved
 */

namespace Retailex\Api;

use Log\Service\LogService;
use Magelink\Exception\MagelinkException;
use Retailex\Node;
use Zend\ServiceManager\ServiceLocatorAwareInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\Soap\Client;


class Soap implements ServiceLocatorAwareInterface
{

    const SOAP_NAMESPACE = 'http://retailexpress.com.au/';
    const SOAP_NAME = 'ClientHeader';
    const DEFAULT_WSDL_PREFIX = 'dotnet/admin/webservices/v2/webstore/service.asmx?wsdl';

    /** @var Node|NULL $this->node */
    protected $node = NULL;
    /** @var Client|NULL $this->soapClient */
    protected $soapClient = NULL;

    /** @var ServiceLocatorInterface $this->serviceLocator */
    protected $serviceLocator;

    /**
     * Get service locator
     * @return ServiceLocatorInterface
     */
    public function getServiceLocator()
    {
        return $this->serviceLocator;
    }

    /**
     * Set service locator
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function setServiceLocator(ServiceLocatorInterface $serviceLocator)
    {
        $this->serviceLocator = $serviceLocator;
    }

    /**
     * @param Node $retailexNode The Magento node we are representing communications for
     * @return bool Whether we successfully connected
     * @throws MagelinkException If this API has already been initialized
     */
    public function init(Node $retailexNode)
    {
        $this->node = $retailexNode;
        return $this->_init();
    }

    /**
     * @return string $initLogCode
     */
    protected function getInitLogCode()
    {
        return 'rex_isoap';
    }

    /**
     * @param array $header
     * @return NULL|Client $this->soapClient
     */
    protected function storeSoapClient(array $header)
    {
        $url = trim(trim($this->node->getConfig('retailex-url')), '/');
        $urlPrefix = ltrim(trim($this->node->getConfig('retailex-wsdl')), '/');

        if (strlen($urlPrefix) == 0) {
            $urlPrefix = self::DEFAULT_WSDL_PREFIX;
        }

        $url .= '/'.$urlPrefix;
        $soapHeader = new \SoapHeader(self::SOAP_NAMESPACE, self::SOAP_NAME, $header);

        $this->soapClient = new Client($url, array('soap_version'=>SOAP_1_2));
        $this->soapClient->addSoapInputHeader($soapHeader);

        return $this->soapClient;
    }

    /**
     * Sets up the SOAP API, connects to Retail Express, and performs a login.
     * @return bool Whether we successfully connected
     * @throws MagelinkException If this API has already been initialized
     */
    protected function _init()
    {
        $success = FALSE;

        if (is_null($this->node)) {
            throw new MagelinkException('Retail Express node is not available on the SOAP API!');
        }elseif (!is_null($this->soapClient)) {
            throw new MagelinkException('Tried to initialize Soap API twice!');
        }else{
            $soapHeader =  array(
                'clientId'=>$this->node->getConfig('retailex-client'),
                'username'=>$this->node->getConfig('retailex-username'),
                'password'=>$this->node->getConfig('retailex-password')
            );

            $logLevel = LogService::LEVEL_ERROR;
            $logCode = $this->getInitLogCode();
            $logData = array('soap header'=>$soapHeader);

            if (!$soapHeader['clientId'] || !$soapHeader['username'] || !$soapHeader['password']) {
                $logCode .= '_fail';
                $logMessage = 'SOAP initialisation failed: Please check client id, username and password.';
            }else{
                $this->storeSoapClient($soapHeader);
                $logLevel = LogService::LEVEL_INFO;
                $logMessage = 'SOAP was sucessfully initialised.';
            }

            $this->getServiceLocator()->get('logService')->log($logLevel, $logCode, $logMessage, $logData);
        }

        return $success;
    }

    /**
     * Make a call to SOAP API, automatically adding required headers/sessions/etc and processing response
     * @param string $call The name of the call to make
     * @param array $data The data to be passed to the call (as associative/numerical arrays)
     * @throws \SoapFault
     * @return array|mixed Response data
     */
    public function call($call, $data)
    {
        $retry = FALSE;
        do {
            try{
                $result = $this->_call($call, $data);
                $success = TRUE;
            }catch(MagelinkException $exception) {
                $success = FALSE;
                $retry = !$retry;
                $soapFault = $exception->getPrevious();

                if ($retry === TRUE && (strpos(strtolower($soapFault->getMessage()), 'session expired') !== FALSE
                    || strpos(strtolower($soapFault->getMessage()), 'try to relogin') !== FALSE)) {

                    $this->soapClient = NULL;
                    $this->_init();
                }
            }
        }while ($retry === TRUE && $success === FALSE);

        if ($success !== TRUE) {
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_ERROR, 'rex_soap_fault', $exception->getMessage(),
                    array(
                        'data'=>$data,
                        'code'=>$soapFault->getCode(),
                        'trace'=>$soapFault->getTraceAsString(),
                        'request'=>$this->soapClient->getLastRequest(),
                        'response'=>$this->soapClient->getLastResponse())
                );
            // ToDo: Check if this additional logging is necessary
            $this->forceStdoutDebug();
            throw $exception;
            $result = NULL;
        }else{
            $result = $this->_processResponse($result);
            /* ToDo: Investigate if that could be centralised
            if (isset($result['result'])) {
                $result = $result['result'];
            }*/

            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_DEBUG, 'rex_soap_success', 'Successfully soap call: '.$call,
                    array('call'=>$call, 'data'=>$data, 'result'=>$result));
        }

        return $result;
    }

    /**
     * Make a call to SOAP API, automatically adding required headers/sessions/etc and processing response
     * @param string $call The name of the call to make
     * @param array $data The data to be passed to the call (as associative/numerical arrays)
     * @throws \SoapFault
     * @return array|mixed Response data
     */
    protected function _call($call, $data)
    {
        if (!is_array($data)) {
            if (is_object($data)) {
                $data = get_object_vars($data);
            }else{
                $data = array($data);
            }
        }

        array_unshift($data, $this->sessionId);

        try{
            $result = $this->soapClient->call($call, $data);
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_DEBUGEXTRA,
                    'rex_soap_call',
                    'Successful SOAP call '.$call.'.',
                    array('data'=>$data, 'result'=>$result)
                );
        }catch (\SoapFault $soapFault) {
            throw new MagelinkException('SOAP Fault with call '.$call.': '.$soapFault->getMessage(), 0, $soapFault);
        }

        return $result;
    }

    /**
     * Forced debug output to command line
     */
    public function forceStdoutDebug()
    {
        echo PHP_EOL.$this->soapClient->getLastRequest().PHP_EOL.$this->soapClient->getLastResponse().PHP_EOL;
    }

    /**
     * Processes response from SOAP api to convert all std_class object structures to associative/numerical arrays
     * @param mixed $array
     * @return array
     */
    protected function _processResponse($array)
    {
        if (is_object($array)) {
            $array = get_object_vars($array);
        }

        $result = $array;
        if (is_array($array)) {
            foreach ($result as $key=>$value) {
                if (is_object($value) || is_array($value)){
                    $result[$key] = $this->_processResponse($value);
                }
            }
        }

        if (is_object($result)) {
            $result = get_object_vars($result);
        }

        return $result;
    }

}