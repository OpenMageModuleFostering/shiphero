<?php

$installer = $this;
$installer->startSetup();

// Hold error message
$errors = array();

// Check for duplicate Consumer
$consumerName = 'ShipHero';
$callbackUrl = 'http://app.shiphero.com/magento/callback';

// Database Read Adapter
$coreResource = Mage::getSingleton('core/resource');
$read = $coreResource->getConnection('core_read');

// Tables
$oauthConsumerTable = $coreResource->getTableName('oauth_consumer');
$adminUserTable = $coreResource->getTableName('admin_user');
$api2AclUserTable = $coreResource->getTableName('api2_acl_user');
$api2AclRoleTable = $coreResource->getTableName('api2_acl_role');
$api2AclRuleTable = $coreResource->getTableName('api2_acl_rule');
$api2AclAttributeTable = $coreResource->getTableName('api2_acl_attribute');

$query = "SELECT entity_id FROM $oauthConsumerTable WHERE name = ? AND callback_url = ?";
$result = $read->fetchRow($query, array($consumerName, $callbackUrl));

// If we don't have an existing consumer create one
if(empty($result)){
    // Create Consumer
    $consumer = Mage::getModel('oauth/consumer');

    /** @var $helper Mage_Oauth_Helper_Data */
    $helper = Mage::helper('oauth');

    $data = array(
        'name'          => $consumerName,
        'key'           => $helper->generateConsumerKey(),
        'secret'        => $helper->generateConsumerSecret(),
        'callback_url'  => 'http://app.shiphero.com/magento/callback'
    );

    $consumer->addData($data);
    try {
        $consumer->save();
    }catch(Exception $e){
        array_push($errors, $e->getMessage());
    }
}else{
    array_push($errors, 'Consumer already exists.');
}

// Get Admin User
$query = "SELECT user_id FROM $adminUserTable WHERE is_active = ? ORDER BY user_id ASC LIMIT 1";
$admin = $read->fetchRow($query, array(1));

// Get Admin User To API User Association
$query = "SELECT admin_id, role_id FROM $api2AclUserTable WHERE admin_id = ?";
$role = $read->fetchRow($query, $admin['user_id']);

// Check for existing Admin Role
$query = "SELECT entity_id FROM $api2AclRoleTable WHERE role_name = ?";
$result = $read->fetchRow($query, array($consumerName));

if(empty($result)){
    // Create Admin API Role
    $transaction = $coreResource->getConnection('core_write');
    $now = date('Y-m-d H:i:s',time());
    try {
        $transaction->beginTransaction();

        // Add API Admin User Role
        $query = "INSERT INTO $api2AclRoleTable (created_at, role_name) VALUES (?, ?)";
        $transaction->query($query, array($now, $consumerName));
        $lastInsertId = $transaction->lastInsertId();

        // Add API Privileges For User Role
        $query = "INSERT INTO $api2AclRuleTable (role_id, resource_id) VALUES (?, ?)";
        $transaction->query($query, array($lastInsertId, 'all'));

        // Add ACL Attribute Privileges For Admin Role
        $query = "INSERT IGNORE INTO $api2AclAttributeTable (user_type, resource_id) VALUES (?, ?)";
        $transaction->query($query, array('admin', 'all'));

        // Associate Admin User To API User
        $query = "INSERT INTO $api2AclUserTable (admin_id, role_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE role_id = ?";
        $transaction->query($query, array($admin['user_id'], $lastInsertId, $lastInsertId));

        $transaction->commit();
    } catch (Exception $e) {
        array_push($errors, $e->getMessage());
        $transaction->rollback();
    }
}else{
    array_push($errors, 'Admin role already exists.');
}

//Send an email if there is an error
$currentStore = $_SERVER['HTTP_HOST'];
$fromEmail = "noreply@shiphero.com";
$fromName = $currentStore;
$toEmail = "support@shiphero.com";
$toName = "ShipHero Support";
$subject = "Magento Installation: " . $currentStore;
$msgErrors = (!empty($errors)) ? "Errors: " . implode(".\n", $errors) : '';
$msg = "New installation from " . $currentStore . "\n\n" . $msgErrors;
$body = $msg;

try{
    $mail = new Zend_Mail();
    $mail->setFrom($fromEmail, $fromName);
    $mail->addTo($toEmail, $toName);
    $mail->setSubject($subject);
    $mail->setBodyHtml($body); // here u also use setBodyText options.

    $mail->send();
}catch(Exception $e){
    error_log($e->getMessage());
}

$installer->endSetup();
