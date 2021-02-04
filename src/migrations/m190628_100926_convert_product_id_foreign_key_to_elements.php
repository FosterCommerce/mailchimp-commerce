<?php

namespace ether\mc\migrations;

use craft\db\Migration;
use craft\helpers\MigrationHelper;

/**
 * m190628_100926_convert_product_id_foreign_key_to_elements migration.
 */
class m190628_100926_convert_product_id_foreign_key_to_elements extends Migration
{

    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        MigrationHelper::dropForeignKeyIfExists('{{%mc_products_synced}}', ['productId'], $this);

        $this->addForeignKey(
	        null,
	        '{{%mc_products_synced}}',
	        ['productId'],
	        '{{%elements}}',
	        ['id'],
	        'CASCADE'
        );
    }

    /**
     * @inheritdoc
     */
    public function safeDown()
    {
        echo "m190628_100926_convert_product_id_foreign_key_to_elements cannot be reverted.\n";
        return false;
    }

}
