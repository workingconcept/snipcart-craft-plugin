<?php

namespace fostercommerce\snipcart\migrations;

use craft\db\Migration;
use fostercommerce\snipcart\controllers\WebhooksController;
use fostercommerce\snipcart\db\Table;

/**
 * m200921_165936_webhook_types migration.
 */
class m200921_165936_webhook_types extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        if ($this->getDb()->tableExists(Table::WEBHOOK_LOG)) {
            /**
             * Update `type` enum to include new webhook events.
             */
            $typeValues = array_keys(WebhooksController::WEBHOOK_EVENT_MAP);
            $this->alterColumn(
                Table::WEBHOOK_LOG,
                'type',
                $this->enum('type', $typeValues)
            );
        }
    }

    /**
     * @inheritdoc
     */
    public function safeDown()
    {
        echo "m200921_165936_webhook_types cannot be reverted.\n";
        return false;
    }
}