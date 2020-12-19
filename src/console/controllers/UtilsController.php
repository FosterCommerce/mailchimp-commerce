<?php

namespace ether\mc\console\controllers;

use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use ether\mc\MailchimpCommerce;

class UtilsController extends Controller
{
    public function actionDeleteMailchimpProduct($productId)
    {
        if ($productId != (int)$productId || $productId <= 0) {
            $this->stdout('Missing or invalid product ID', Console::FG_RED);
            $this->stdout(PHP_EOL);
            return ExitCode::USAGE;
        }

        if (!MailchimpCommerce::getInstance()->products->deleteProductById($productId)) {
            $this->stdout('Unable to delete product ID ' . $productId, Console::FG_RED);
            $this->stdout(PHP_EOL);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout('Successfully deleted product ID ' . $productId . ' from Mailchimp', Console::FG_GREEN);
        $this->stdout(PHP_EOL);
        return ExitCode::OK;
    }
}
