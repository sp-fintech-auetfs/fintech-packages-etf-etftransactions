<?php

namespace Apps\Fintech\Packages\Etf\Transactions\Model;

use System\Base\BaseModel;

class AppsFintechEtfTransactions extends BaseModel
{
    public $id;

    public $account_id;

    public $user_id;

    public $portfolio_id;

    public $strategy_id;

    public $amc_id;

    public $scheme_id;

    public $date;

    public $date_closed;

    public $amount;

    public $nav;

    public $available_amount;

    public $type;//Buy/Sell

    public $sell_all;//Flag if the sell transaction was sell all units.

    public $details;

    public $units_bought;

    public $units_sold;

    public $transactions;//Associated Transactions. For buy, sell transactions. For sell, buy transactions.

    public $latest_value;

    public $latest_value_date;

    public $amc_transaction_id;
    //Open/Close - Example: If we purchase 10 units (tx_id: 1) we mark that status of the transaction open.
    //When we sell lets say 5 units, the units will be checked against all open tx_ids and using FIFO, we should close the tx_ids.
    public $status;

    public $diff;

    public $xirr;

    public $returns;
}