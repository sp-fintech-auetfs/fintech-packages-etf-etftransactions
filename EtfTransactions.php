<?php

namespace Apps\Fintech\Packages\Etf\Transactions;

use Apps\Fintech\Packages\Etf\Investments\EtfInvestments;
use Apps\Fintech\Packages\Etf\Portfolios\EtfPortfolios;
use Apps\Fintech\Packages\Etf\Portfoliostimeline\EtfPortfoliostimeline;
use Apps\Fintech\Packages\Etf\Schemes\EtfSchemes;
use Apps\Fintech\Packages\Etf\Transactions\Model\AppsFintechEtfTransactions;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToReadFile;
use System\Base\BasePackage;

class EtfTransactions extends BasePackage
{
    protected $modelToUse = AppsFintechEtfTransactions::class;

    protected $packageName = 'etftransactions';

    public $etftransactions;

    protected $scheme;

    protected $schemesPackage;

    protected $portfolio;

    public function addEtfTransaction($data)
    {
        $this->schemesPackage = $this->usepackage(EtfSchemes::class);

        if (!$this->scheme) {
            $this->scheme = $this->schemesPackage->getSchemeFromEtfCodeOrSchemeId($data, true);

            if (!$this->scheme) {
                $this->addResponse('Scheme with id not found!', 1);

                return false;
            }
        }

        $portfoliosPackage = $this->usepackage(EtfPortfolios::class);

        $portfoliosTimelinePackage = $this->usepackage(EtfPortfoliostimeline::class);

        $investmentsPackage = $this->usepackage(EtfInvestments::class);

        $data['account_id'] = $this->access->auth->account()['id'];

        if (!$this->portfolio) {
            $this->portfolio = $portfoliosPackage->getPortfolioById((int) $data['portfolio_id']);
        }

        if ($data['type'] === 'buy') {
            $data['status'] = 'open';
            $data['user_id'] = $this->portfolio['user_id'];
            $data['available_amount'] = $data['amount'];

            if ($this->calculateTransactionUnitsAndValues($data)) {
                if ($this->add($data)) {
                    if (isset($this->portfolio['investments'][$data['scheme_id']])) {
                        if ($this->portfolio['investments'][$data['scheme_id']]['status'] === 'close') {
                            $this->portfolio['investments'][$data['scheme_id']]['status'] = 'open';
                        }

                        $investmentsPackage->update($this->portfolio['investments'][$data['scheme_id']]);
                    }

                    if (!isset($data['clone']) && !isset($data['via_strategies'])) {
                        $portfoliosPackage->recalculatePortfolio($data, true);

                        $portfoliosTimelinePackage->forceRecalculateTimeline($this->portfolio, $data['date']);
                    }

                    $this->addResponse('Ok', 0);

                    return true;
                }

                $this->addResponse('Error adding transaction', 1);

                return false;
            }

            $this->addResponse('Scheme/Scheme navs information not available!', 1);

            return false;
        } else if ($data['type'] === 'sell') {
            $canSellTransactions = [];

            $this->portfolio = $portfoliosPackage->getPortfolioById((int) $data['portfolio_id']);

            if ($this->portfolio['transactions'] &&
                count($this->portfolio['transactions']) > 0
            ) {
                $this->portfolio['transactions'] = msort(array: $this->portfolio['transactions'], key: 'date', preserveKey: true);

                foreach ($this->portfolio['transactions'] as $transaction) {
                    if ($transaction['scheme_id'] != $this->scheme['id']) {
                        continue;
                    }

                    if ($transaction['status'] === 'close') {
                        continue;
                    }

                    if (\Carbon\Carbon::parse($transaction['date'])->gt(\Carbon\Carbon::parse($data['date']))) {
                        continue;
                    }

                    if ($transaction['type'] === 'buy') {
                        $transaction['available_units'] = $transaction['units_bought'];

                        if ($transaction['units_sold'] > 0) {
                            $transaction['available_units'] = $transaction['units_bought'] - $transaction['units_sold'];
                        }

                        if ($transaction['available_units'] == 0) {
                            continue;
                        }

                        if (isset($canSellTransactions[$transaction['scheme_id']])) {
                            $canSellTransactions[$transaction['scheme_id']]['available_units'] += $transaction['available_units'];
                        } else {
                            $canSellTransactions[$transaction['scheme_id']]['available_units'] = $transaction['available_units'];
                        }
                    }

                    $canSellTransactions[$transaction['scheme_id']]['available_units'] =
                        numberFormatPrecision($canSellTransactions[$transaction['scheme_id']]['available_units'], 2);

                    $canSellTransactions[$transaction['scheme_id']]['returns'] = $this->calculateTransactionReturns($transaction, false, null, $data);

                    $canSellTransactions[$transaction['scheme_id']]['available_amount'] =
                        numberFormatPrecision(
                            $canSellTransactions[$transaction['scheme_id']]['available_units'] * $canSellTransactions[$transaction['scheme_id']]['returns'][$data['date']]['nav'],
                            2
                        );
                }

                if (isset($canSellTransactions[$this->scheme['id']])) {
                    if (isset($data['amount'])) {
                        if ((float) $data['amount'] > $canSellTransactions[$this->scheme['id']]['available_amount']) {
                            if (!isset($data['sell_all']) ||
                                (isset($data['sell_all']) && $data['sell_all'] == 'false')
                            ) {
                                $this->addResponse('Amount exceeds from available amount', 1);

                                return false;
                            }

                            $data['units'] = $canSellTransactions[$this->scheme['id']]['available_units'];
                            $data['amount'] = $canSellTransactions[$this->scheme['id']]['available_amount'];
                        } else {
                            //Convert from $data['amount'] to $data['units']
                            $data['units'] = numberFormatPrecision($data['amount'] / $this->scheme['navs']['navs'][$data['date']]['nav'], 3);
                        }
                    } else if (isset($data['units'])) {
                        if ((float) $data['units'] > $canSellTransactions[$this->scheme['id']]['available_units']) {
                            if (!isset($data['sell_all']) ||
                                (isset($data['sell_all']) && $data['sell_all'] == 'false')
                            ) {
                                $this->addResponse('Units exceeds from available units', 1);

                                return false;
                            }

                            $data['units'] = $canSellTransactions[$this->scheme['id']]['available_units'];
                            $data['amount'] = $canSellTransactions[$this->scheme['id']]['available_amount'];
                        } else {
                            //Convert from $data['units'] to $data['amount']
                            $data['amount'] = $data['units'] * $this->scheme['navs']['navs'][$data['date']]['nav'];
                        }
                    }

                    $data['units_bought'] = 0;
                    $data['units_sold'] = $data['units'];
                    $data['latest_value'] = '-';
                    $data['latest_value_date'] = $data['date'];
                    $data['xirr'] = '-';
                    $data['status'] = 'close';
                    $data['user_id'] = $this->portfolio['user_id'];
                    $data['scheme_id'] = $this->scheme['id'];
                    $data['amc_id'] = $this->scheme['amc_id'];
                    $data['date_closed'] = $data['date'];
                    $data['nav'] = $canSellTransactions[$this->scheme['id']]['returns'][$data['date']]['nav'];

                    if ($this->add($data)) {
                        $data = array_merge($data, $this->packagesData->last);

                        $buyTransactions = [];
                        $sellTransactions = [];
                        $unitsToProcess = (float) $data['units'];

                        if ($unitsToProcess > 0) {
                            foreach ($this->portfolio['transactions'] as $transaction) {
                                if ($unitsToProcess <= 0) {
                                    break;
                                }

                                if ($transaction['status'] === 'close' || $transaction['type'] === 'sell') {
                                    continue;
                                }

                                if ($transaction['scheme_id'] != $this->scheme['id']) {
                                    continue;
                                }

                                if (\Carbon\Carbon::parse($transaction['date'])->gt(\Carbon\Carbon::parse($data['date']))) {
                                    continue;
                                }

                                $transaction['returns'] = $this->calculateTransactionReturns($transaction, false, null, $data);

                                if ($transaction['type'] === 'buy' && $transaction['status'] === 'open') {
                                    $availableUnits = $transaction['units_bought'] - $transaction['units_sold'];

                                    if (isset($data['sell_all']) && $data['sell_all'] == 'true') {
                                        $buyTransactions[$transaction['id']]['id'] = $transaction['id'];
                                        $buyTransactions[$transaction['id']]['date'] = $transaction['date'];
                                        $buyTransactions[$transaction['id']]['units'] = numberFormatPrecision($availableUnits, 3);
                                        $buyTransactions[$transaction['id']]['amount'] = numberFormatPrecision($availableUnits * $transaction['returns'][$data['date']]['nav'], 2);
                                        $buyTransactions[$transaction['id']]['returns'] = $canSellTransactions[$transaction['scheme_id']]['returns'][$data['date']];

                                        $sellTransactions[$data['id']]['id'] = $data['id'];
                                        $sellTransactions[$data['id']]['date'] = $data['date'];
                                        $sellTransactions[$data['id']]['units'] = numberFormatPrecision($availableUnits, 3);
                                        $sellTransactions[$data['id']]['amount'] = numberFormatPrecision($availableUnits * $transaction['returns'][$data['date']]['nav'], 2);
                                        $sellTransactions[$data['id']]['returns'] = $canSellTransactions[$transaction['scheme_id']]['returns'][$data['date']];

                                        $transaction['units_sold'] = $transaction['units_bought'];

                                        $transaction['status'] = 'close';
                                        $transaction['date_closed'] = $data['date'];
                                        $transaction['available_amount'] = 0;
                                    } else {
                                        if ($availableUnits <= $unitsToProcess) {
                                            $transaction['units_sold'] = $transaction['units_bought'];

                                            $buyTransactions[$transaction['id']]['id'] = $transaction['id'];
                                            $buyTransactions[$transaction['id']]['date'] = $transaction['date'];
                                            $buyTransactions[$transaction['id']]['units'] = numberFormatPrecision($availableUnits, 3);
                                            $buyTransactions[$transaction['id']]['amount'] = numberFormatPrecision($availableUnits * $transaction['returns'][$data['date']]['nav'], 2);
                                            $buyTransactions[$transaction['id']]['returns'] = $canSellTransactions[$transaction['scheme_id']]['returns'][$data['date']];

                                            $sellTransactions[$data['id']]['id'] = $data['id'];
                                            $sellTransactions[$data['id']]['date'] = $data['date'];
                                            $sellTransactions[$data['id']]['units'] = numberFormatPrecision($availableUnits, 3);
                                            $sellTransactions[$data['id']]['amount'] = numberFormatPrecision($availableUnits * $transaction['returns'][$data['date']]['nav'], 2);
                                            $sellTransactions[$data['id']]['returns'] = $canSellTransactions[$transaction['scheme_id']]['returns'][$data['date']];

                                            $transaction['status'] = 'close';
                                            $transaction['date_closed'] = $data['date'];
                                        } else if ($availableUnits > $unitsToProcess) {
                                            $transaction['units_sold'] = $transaction['units_sold'] + numberFormatPrecision($unitsToProcess, 3);

                                            $buyTransactions[$transaction['id']]['id'] = $transaction['id'];
                                            $buyTransactions[$transaction['id']]['date'] = $transaction['date'];
                                            $buyTransactions[$transaction['id']]['units'] = numberFormatPrecision($unitsToProcess, 3);
                                            $buyTransactions[$transaction['id']]['amount'] = numberFormatPrecision($unitsToProcess * $transaction['returns'][$data['date']]['nav'], 2);
                                            $buyTransactions[$transaction['id']]['returns'] = $canSellTransactions[$transaction['scheme_id']]['returns'][$data['date']];

                                            $sellTransactions[$data['id']]['id'] = $data['id'];
                                            $sellTransactions[$data['id']]['date'] = $data['date'];
                                            $sellTransactions[$data['id']]['units'] = numberFormatPrecision($unitsToProcess, 3);
                                            $sellTransactions[$data['id']]['amount'] = numberFormatPrecision($unitsToProcess * $transaction['returns'][$data['date']]['nav'], 2);
                                            $sellTransactions[$data['id']]['returns'] = $canSellTransactions[$transaction['scheme_id']]['returns'][$data['date']];
                                        }

                                        $unitsToProcess = $unitsToProcess - $availableUnits;
                                    }

                                    if (isset($transaction['transactions'])) {
                                        if (is_string($transaction['transactions'])) {
                                            $transaction['transactions'] = $this->helper->decode($transaction['transactions'], true);
                                        }

                                        $transaction['transactions'] = array_replace($transaction['transactions'], $sellTransactions);
                                    } else {
                                        $transaction['transactions'] = $sellTransactions;
                                    }

                                    $this->update($transaction);
                                }
                            }
                        }

                        $data['transactions'] = $buyTransactions;

                        if ($this->update($data)) {
                            if (!isset($data['clone']) && !isset($data['via_strategies'])) {
                                if (isset($data['sell_all']) && $data['sell_all'] == 'true') {
                                    $this->portfolio['investments'][$data['scheme_id']]['units'] = 0;
                                } else {
                                    $this->portfolio['investments'][$data['scheme_id']]['units'] =
                                        $this->portfolio['investments'][$data['scheme_id']]['units'] - $data['units'];
                                }

                                if ($this->portfolio['investments'][$data['scheme_id']]['units'] == 0) {
                                    $this->portfolio['investments'][$data['scheme_id']]['status'] = 'close';
                                }

                                $investmentsPackage->update($this->portfolio['investments'][$data['scheme_id']]);

                                $portfoliosPackage->recalculatePortfolio($data, true);

                                $portfoliosTimelinePackage->forceRecalculateTimeline($this->portfolio, $data['date']);
                            }

                            $this->addResponse('Ok', 0);

                            return true;
                        }
                    }
                }

                $this->addResponse('Error: There are no buy transactions with this Scheme.', 1);

                return false;
            }

            $this->addResponse('Error: There are currently no transactions for this portfolio', 1);

            return false;
        }

        $this->addResponse('Added transaction');

        return true;
    }

    public function updateEtfTransaction($data)
    {
        $portfoliosPackage = $this->usepackage(EtfPortfolios::class);

        $portfoliosTimelinePackage = $this->usepackage(EtfPortfoliostimeline::class);

        $etfTransaction = $this->getById((int) $data['id']);

        $this->portfolio = $portfoliosPackage->getPortfolioById((int) $etfTransaction['portfolio_id']);

        if ($etfTransaction) {
            if ($etfTransaction['type'] === 'buy' &&
                $etfTransaction['status'] === 'open' &&
                $etfTransaction['units_sold'] === 0
            ) {
                $etfTransactionOriginalDate = $etfTransaction['date'];
                $etfTransaction['date'] = $data['date'];
                $etfTransaction['amount'] = $data['amount'];
                $etfTransaction['scheme_id'] = $data['scheme_id'];
                $etfTransaction['amc_transaction_id'] = $data['amc_transaction_id'];
                $etfTransaction['details'] = $data['details'];

                if ($this->calculateTransactionUnitsAndValues($etfTransaction, true)) {
                    if ($this->update($etfTransaction)) {
                        $portfoliosPackage->recalculatePortfolio($etfTransaction, true);

                        $transactionDate = $etfTransactionOriginalDate;

                        if (\Carbon\Carbon::parse($data['date'])->lt(\Carbon\Carbon::parse($etfTransactionOriginalDate))) {
                            $transactionDate = $data['date'];
                        }

                        $portfoliosTimelinePackage->forceRecalculateTimeline($this->portfolio, $transactionDate);

                        $this->addResponse('Ok', 0);

                        return true;
                    }

                    $this->addResponse('Error adding transaction', 1);

                    return false;
                }

                $this->addResponse('Error getting transaction units', 1);

                return false;
            } else {
                $this->addResponse('Transaction is either closed or has units already sold. Cannot update!', 1);

                return false;
            }
        } else {
            $this->addResponse('Transaction is not found!', 1);

            return false;
        }

        $this->addResponse('Updated transaction');
    }

    public function removeEtfTransaction($data)
    {
        $portfoliosPackage = $this->usepackage(EtfPortfolios::class);

        $portfoliosTimelinePackage = $this->usepackage(EtfPortfoliostimeline::class);

        $investmentsPackage = $this->usepackage(EtfInvestments::class);

        $etfTransaction = $this->getById($data['id']);

        if (!$etfTransaction) {
            $this->addResponse('Id not found', 1);

            return false;
        }

        if (!$this->portfolio) {
            $this->portfolio = $portfoliosPackage->getPortfolioById((int) $etfTransaction['portfolio_id']);
        }

        if ($etfTransaction) {
            if ($etfTransaction['type'] === 'buy') {
                if ($etfTransaction['status'] !== 'open' || $etfTransaction['units_sold'] > 0) {
                    $this->addResponse('Transaction is being used by other transactions. Cannot remove', 1);

                    return false;
                }

                if ($this->remove($etfTransaction['id'])) {
                    $this->portfolio['investments'][$etfTransaction['scheme_id']]['units'] =
                        $this->portfolio['investments'][$etfTransaction['scheme_id']]['units'] - $etfTransaction['units_bought'];

                    if ($this->portfolio['investments'][$etfTransaction['scheme_id']]['units'] == 0) {
                        $this->portfolio['investments'][$etfTransaction['scheme_id']]['status'] = 'close';
                    }

                    $investmentsPackage->update($this->portfolio['investments'][$etfTransaction['scheme_id']]);

                    unset($this->portfolio['transactions'][$etfTransaction['id']]);

                    $portfoliosPackage->update($this->portfolio);

                    $portfoliosPackage->recalculatePortfolio($etfTransaction, true);

                    $portfoliosTimelinePackage->forceRecalculateTimeline($this->portfolio, $etfTransaction['date']);

                    $this->addResponse('Transaction removed');

                    return true;
                }
            } else if ($etfTransaction['type'] === 'sell') {
                if ($etfTransaction['transactions']) {
                    if (is_string($etfTransaction['transactions'])) {
                        $etfTransaction['transactions'] = $this->helper->decode($etfTransaction['transactions']);
                    }

                    if (count($etfTransaction['transactions']) > 0) {
                        foreach ($etfTransaction['transactions'] as $correspondingTransactionArr) {
                            $correspondingTransaction = $this->getById((int) $correspondingTransactionArr['id']);

                            if ($correspondingTransaction) {
                                if ($correspondingTransaction['type'] === 'buy') {
                                    $correspondingTransaction['status'] = 'open';
                                    $correspondingTransaction['date_closed'] = null;

                                    $correspondingTransaction['units_sold'] = numberFormatPrecision($correspondingTransaction['units_sold'] - $correspondingTransactionArr['units'], 3);

                                    unset($correspondingTransaction['transactions'][$etfTransaction['id']]);
                                }
                            }

                            $this->update($correspondingTransaction);
                        }
                    }
                }

                if ($this->remove($etfTransaction['id'])) {
                    $portfoliosPackage->update($portfolio);

                    if (isset($this->portfolio['investments'][$etfTransaction['scheme_id']]) &&
                        $this->portfolio['investments'][$etfTransaction['scheme_id']]['status'] === 'close'
                    ) {
                        $this->portfolio['investments'][$etfTransaction['scheme_id']]['status'] = 'open';

                        $investmentsPackage->update($this->portfolio['investments'][$etfTransaction['scheme_id']]);
                    }

                    $portfoliosPackage->recalculatePortfolio($etfTransaction, true);

                    $portfoliosTimelinePackage->forceRecalculateTimeline($this->portfolio, $etfTransaction['date']);

                    $this->addResponse('Transaction removed');

                    return true;
                }
            }

            $this->addResponse('Unknown Transaction type. Contact developer!', 1);

            return false;
        }

        $this->addResponse('Error, contact developer', 1);
    }

    public function calculateTransactionUnitsAndValues(&$transaction, $update = false, $timeline = null, $sellTransactionData = null, $schemes = null)
    {
        if (!$this->schemesPackage) {
            $this->schemesPackage = $this->usepackage(EtfSchemes::class);
        }

        if ($timeline && isset($timeline->portfolioSchemes[$transaction['scheme_id']])) {
            $this->scheme = &$timeline->portfolioSchemes[$transaction['scheme_id']];
        } else if ($schemes && isset($schemes[$transaction['scheme_id']])) {
            $this->scheme = &$schemes[$transaction['scheme_id']];
        } else {
            if (!$this->scheme) {
                $this->scheme = $this->schemesPackage->getSchemeFromEtfCodeOrSchemeId($transaction, true);
            }
        }

        if ($this->scheme) {
            $transaction['scheme_id'] = $this->scheme['id'];
            $transaction['amc_id'] = $this->scheme['amc_id'];

            if ($this->scheme['navs'] && isset($this->scheme['navs']['navs'][$transaction['date']])) {
                $transaction['nav'] = $this->scheme['navs']['navs'][$transaction['date']]['nav'];

                $units = (float) $transaction['amount'] / $transaction['nav'];

                if ($transaction['type'] === 'buy') {
                    $transaction['units_bought'] = numberFormatPrecision($units, 3);
                }

                if (!isset($transaction['units_sold'])) {
                    $transaction['units_sold'] = 0;
                }

                $transaction['returns'] = $this->calculateTransactionReturns($transaction, $update, $timeline, $sellTransactionData);

                //We calculate the total number of units for latest_value
                if ($timeline && $timeline->timelineDateBeingProcessed) {
                    $lastTransactionNav = $transaction['returns'][$timeline->timelineDateBeingProcessed];
                } else {
                    $lastTransactionNav = $this->helper->last($transaction['returns']);
                }

                if ($lastTransactionNav) {
                    $transaction['latest_value_date'] = $lastTransactionNav['date'];
                    $transaction['latest_value'] = $lastTransactionNav['total_return'];
                } else {
                    $transaction['latest_value_date'] = 0;
                    $transaction['latest_value'] = 0;
                }

                return $transaction;
            }
        }

        return false;
    }

    public function calculateTransactionReturns($transaction, $update = false, $timeline = null, $sellTransactionData = null)
    {
        if (!$timeline && $transaction['status'] === 'close') {
            return $transaction['returns'];
        }

        if (!isset($transaction['returns']) || $update || $timeline) {
            $transaction['returns'] = [];
        }

        if ($transaction['type'] === 'buy') {
            if ($timeline) {
                if (!isset($timeline->parsedCarbon[$timeline->timelineDateBeingProcessed])) {
                    $timeline->parsedCarbon[$timeline->timelineDateBeingProcessed] = \Carbon\Carbon::parse($timeline->timelineDateBeingProcessed);
                }

                $units = 0;

                //Check this for timeline!!
                if ($transaction['transactions'] && count($transaction['transactions']) > 0) {
                    foreach ($transaction['transactions'] as $soldTransaction) {
                        if (!isset($timeline->parsedCarbon[$soldTransaction['date']])) {
                            $timeline->parsedCarbon[$soldTransaction['date']] = \Carbon\Carbon::parse($soldTransaction['date']);
                        }

                        if (($timeline->parsedCarbon[$timeline->timelineDateBeingProcessed])->gte($timeline->parsedCarbon[$soldTransaction['date']])) {
                            $units = $units + $soldTransaction['units'];
                        }
                    }
                }

                $units = numberFormatPrecision($transaction['units_bought'] - $units, 3);
            } else {
                $units = numberFormatPrecision($transaction['units_bought'] - $transaction['units_sold'], 3);
            }
        } else {
            $units = $transaction['units_sold'];
        }

        if ($units < 0) {
            $units = 0;
        }

        $navs = &$this->scheme['navs']['navs'];
        $navsKeys = array_keys($navs);
        $navsToProcess = [];

        if ($timeline && $timeline->timelineDateBeingProcessed) {
            if (!isset($navs[$timeline->timelineDateBeingProcessed])) {
                $timeline->timelineDateBeingProcessed = $this->helper->last($navs)['date'];
            }

            $navsToProcess[$transaction['date']] = $navs[$transaction['date']];
            $navsToProcess[$timeline->timelineDateBeingProcessed] = $navs[$timeline->timelineDateBeingProcessed];
        } else {
            $transactionDateKey = array_search($transaction['date'], $navsKeys);

            $navs = array_slice($navs, $transactionDateKey);

            $navsToProcess[$this->helper->firstKey($navs)] = $this->helper->first($navs);

            if ($sellTransactionData && isset($sellTransactionData['date'])) {
                $navsToProcess[$sellTransactionData['date']] = $navs[$sellTransactionData['date']];
            }

            $navsToProcess[$this->helper->lastKey($navs)] = $this->helper->last($navs);
        }

        $transaction['returns'] = [];

        foreach ($navsToProcess as $nav) {
            $transaction['returns'][$nav['date']] = [];
            $transaction['returns'][$nav['date']]['date'] = $nav['date'];
            $transaction['returns'][$nav['date']]['timestamp'] = $nav['timestamp'];
            $transaction['returns'][$nav['date']]['nav'] = $nav['nav'];
            $transaction['returns'][$nav['date']]['units'] = $units;
            $transaction['returns'][$nav['date']]['total_return'] = numberFormatPrecision($nav['nav'] * $units, 2);//Units bought - units sold

            if (isset($transaction['date_closed']) &&
                ($nav['date'] === $transaction['date_closed'])
            ) {
                break;
            }
        }

        $transaction['returns'] = msort(array: $transaction['returns'], key: 'date', preserveKey: true);

        return $transaction['returns'];
    }
}