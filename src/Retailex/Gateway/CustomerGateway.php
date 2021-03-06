<?php
/**
 * @package Retailex\Gateway
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2016 LERO9 Ltd.
 * @license http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause - Please view LICENSE.md for more information
 */

namespace Retailex\Gateway;

use Entity\Entity;
use Entity\Service\EntityService;
use Entity\Wrapper\Address;
use Log\Service\LogService;
use Magelink\Exception\GatewayException;
use Magelink\Exception\SyncException;


class CustomerGateway extends AbstractGateway
{

    const GATEWAY_ENTITY = 'customer';
    const GATEWAY_ENTITY_CODE = 'cu';

    /** @var array $this->defaultAttributeMapping */
    protected $defaultAttributeMapping = array(
        'DelAddress'=>self::ATTRIBUTE_NOT_DEFINED,
        'DelPostCode'=>self::ATTRIBUTE_NOT_DEFINED,
        'DelSuburb'=>self::ATTRIBUTE_NOT_DEFINED,
        'DelState'=>self::ATTRIBUTE_NOT_DEFINED,
        'ReceiverNews'=>0
    );
    /** @var array $this->billingAttributeMapping */
    protected $billingAttributeMapping = array(
        'BillFirstName'=>'first_name',
        'BillLastName'=>'last_name',
        'BillCompany'=>'company',
        'BillPhone'=>'telephone',
        'BillPostCode'=>'postcode',
        'BillState'=>'region',
        'BillCountry'=>'country_code'
    );
    /** @var array $this->shippingAttributeMapping */
    protected $shippingAttributeMapping = array(
        'DelCompany'=>'company',
        'DelPhone'=>'telephone',
        'DelPostCode'=>'postcode',
        'DelCountry'=>'country_code'
    );

    /**
     * Initialize the gateway and perform any setup actions required.
     * @param string $entityType
     * @return bool $success
     * @throws GatewayException
     */
    protected function _init($entityType)
    {
        $success = parent::_init($entityType);

        if ($entityType != 'customer' && $entityType != 'address') {
            throw new GatewayException('Invalid entity type '.$entityType.' for this gateway');
            $success = FALSE;
        }else{
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_DEBUG, 'rex_cu_init', 'Initialised Retailex customer gateway.', array());
        }

        return $success;
    }

    /**
     * TECHNICAL DEBT // ToDo: Implement this method
     */
    protected function retrieveEntities()
    {
        // ToDo: Implement customer retrieval
        $this->getServiceLocator()->get('logService')->log(LogService::LEVEL_INFO, 'rex_cu_re_no',
            'Customer retrieval not implemented yet.', array());
        $retailExpressData = array();

        return count($retailExpressData);
    }

    /**
     * @return string $randomPassword
     */
    private function getRandomPassword()
    {
        $password = '';
        for ($c = 0; $c < 16; $c++)
        {
            do {
                $ascii = rand(45, 122);
            }while ($ascii > 45 && $ascii < 48 || $ascii > 57 && $ascii < 65 || $ascii > 90 && $ascii < 97);

            $password .= chr($ascii);
        }

        return $password;
    }

    /**
     * Write out all the updates to the given entity.
     * @param Entity $entity
     * @param \Entity\Attribute[] $attributes
     * @param int $type
     * @return bool
     */
    public function writeUpdates(Entity $entity, $attributes, $type = \Entity\Update::TYPE_UPDATE)
    {
        /** @var EntityService $entityService */
        $entityService = $this->getServiceLocator()->get('entityService');
        $nodeId = $this->_node->getNodeId();
        $entityType = $entity->getTypeStr();
        $attributes = array_unique(array_merge($attributes, array('first_name', 'last_name')));

        if ($entityType == 'address') {
            try{
                $entity = $entity->getParent(TRUE);
            }catch (\Exception $exception) {
                $addressUnique = $entity->getUniqueId();
                $entity = NULL;
            }
            $attributes = array();
        }

        if (is_null($entity)) {
            $success = FALSE;
            $message = 'Error creating/updating address '.$addressUnique.' on Retailex: '.$exception->getMessage();
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_ERROR, 'rex_cu_wr_adderr', $message, array());
        }else{
            $success = TRUE;
            $data = $this->defaultAttributeMapping;
            $data['Password'] = $this->getRandomPassword();
            $data['BillEmail'] = $entity->getUniqueId();

            /** @var Address $billingAddress */
            $billingAddress = $entity->resolve('billing_address', 'address');
            /** @var Address $shippingAddress */
            $shippingAddress = $entity->resolve('shipping_address', 'address');

            if (is_null($billingAddress) && !is_null($shippingAddress)) {
                $billingAddress = $shippingAddress;
            }elseif (!is_null($billingAddress) && is_null($shippingAddress)) {
                $shippingAddress = $billingAddress;
            }

            if (!is_null($billingAddress)) {
                $data['BillAddress'] = $this->getAddress($billingAddress);
                $data['BillAddress2'] = $this->getAddress2($billingAddress);
                $data['BillSuburb'] = $this->getSuburb($billingAddress);

                foreach ($this->billingAttributeMapping as $localCode=>$code) {
                    $value = $billingAddress->getData($code, NULL);
                    if (!is_null($value)) {
                        $data[$localCode] = $value;
                    }
                }
            }
            if (!is_null($shippingAddress)) {
                $name = trim(
                    str_replace(
                        '  ',
                        ' ',
                        $shippingAddress->getData('first_name', '')
                        .' '.$shippingAddress->getData('middle_name', '')
                        .' '.$shippingAddress->getData('last_name', '')
                    )
                );
                $data['DelName'] = (strlen($name) == 0 ? NULL : $name);

                $data['DelAddress'] = $this->getAddress($shippingAddress);
                $data['DelAddress2'] = $this->getAddress2($shippingAddress);
                $data['DelSuburb'] = $this->getSuburb($shippingAddress);
                $data['DelState'] = $this->getState($shippingAddress);

                foreach ($this->shippingAttributeMapping as $localCode=>$code) {
                    $value = $shippingAddress->getData($code, NULL);
                    if (!is_null($value)) {
                        $data[$localCode] = $value;
                    }
                }
            }

            $deliveryName = '';
            foreach ($attributes as $attribute) {
                $value = $entity->getData($attribute);

                // Normal attribute
                switch ($attribute) {
                    case 'enable_newsletter':
                        $data['ReceivesNews'] = ($value == 1 ? 1 : 0);
                        break;
                    case 'first_name':
                        if (!isset($data['BillFirstName'])) {
                            $data['BillFirstName'] = $value;
                        }
                        if (!isset($data['DelName'])) {
                            $deliveryName = trim($value.' '.$deliveryName);
                        }
                        break;
                    case 'middle_name':
                        if (!isset($data['DelName'])) {
                            $deliveryName = trim($deliveryName.' '.$value);
                        }
                        break;
                    case 'last_name':
                        if (!isset($data['BillLastName'])) {
                            $data['BillLastName'] = $value;
                        }
                        if (!isset($data['DelName'])) {
                            $data['DelName'] = trim($deliveryName.' '.$value);
                        }
                        break;
                    case 'date_of_birth':
                    case 'newslettersubscription':
                        // Ignore these attributes
                        break;
                    default:
                        // Warn unsupported attribute
                        break;
                }
            }

            $localId = $entityService->getLocalId($nodeId, $entity);
            if (is_null($localId)) {
                $type = \Entity\Update::TYPE_CREATE;
            }else{
                $type = \Entity\Update::TYPE_UPDATE;
                $data['CustomerId'] = $localId;
                unset($data['Password']);
            }

            $logData = array('type'=>$entityType, 'customer'=>(is_null($entity) ? 'NULL' : $entity->getFullArrayCopy()),
                'attributes'=>$attributes, 'billing'=>$billingAddress, 'shipping'=>$shippingAddress, 'data'=>$data);

            try{
                $call = 'CustomerCreateUpdate';
                $data = array('CustomerXML'=>array('Customers'=>array('Customer'=>$data)));
                $responseXml = $this->soap->call($call, $data);

                $logData['response'] = $responseXml;

                if (is_null($responseXml)) {
                    throw new SyncException('No valid response on '.$call);
                }else{
                    $customerResponse = (array) current($responseXml->xpath('//Customer'));
                    if (isset($customerResponse['Result']) && $customerResponse['Result'] == 'Success') {
                        $localId = $customerResponse['CustomerId'];
                    }
                }
            }catch (\Exception $exception) {
                $message = 'Error on CustomerCreateUpdate: '.$exception->getMessage();
                $this->getServiceLocator()->get('logService')
                    ->log(LogService::LEVEL_ERROR, 'rex_cu_wr_err', $message, $logData);
            }

            if ($type == \Entity\Update::TYPE_CREATE) {
                if (is_null($localId)) {
                    $message = 'Error creating customer in Retailex ('.$entity->getUniqueId().'!'
                        .' Response did not contain a local id.';
                    $this->getServiceLocator()->get('logService')
                        ->log(LogService::LEVEL_ERROR, 'rex_cu_wr_locerr', $message, $logData);
                }else{
                    $entityService->linkEntity($this->_node->getNodeId(), $entity, $localId);
                }
            }
        }

        return $success;
    }

    /**
     * Write out the given action.
     * @param \Entity\Action $action
     * @return bool
     */
    public function writeAction(\Entity\Action $action)
    {
        return NULL;

        /** @var \Entity\Service\EntityService $entityService */
/*        $entityService = $this->getServiceLocator()->get('entityService');

        $entity = $action->getEntity();

        switch($action->getType()){
            case 'delete':
                $this->soap->call('catalogCustomerDelete', array($entity->getUniqueId(), 'sku'));
                break;
            default:
                throw new MagelinkException('Unsupported action type '.$action->getType().' for Retailex Orders.');
        }
*/
    }

}
