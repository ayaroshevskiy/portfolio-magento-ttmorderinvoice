<?php
$installer = $this;

$installer->startSetup();

$installer->getConnection()
    ->addColumn($installer->getTable('sagepay_recurring/profile_payment'), 'admin_invoice_sent', array(
        'type'      => Varien_Db_Ddl_Table::TYPE_INTEGER,
        'unsigned'  => true,
        'nullable'  => false,
        'default'   => '0',
        'comment'   => 'Invoice email sent to Admin flag'
    ));

$installer->endSetup();