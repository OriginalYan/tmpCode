<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();

/**
 * @var array $arParams
 * @var array $arResult
 * @var $APPLICATION
 */

use Bitrix\Main\LoaderException;
use Bitrix\Main\UI\Extension;
use Bitrix\Main\Web\Json;

$this->addExternalCss($this->GetFolder() . '/style.css');
$this->addExternalCss('/bitrix/components/bitrix/main.ui.grid/templates/.default/style.css');
$this->addExternalCss('/bitrix/components/bitrix/report.visualconstructor.widget.content.grid/templates/.default/css/groupingelementstyle.css');

$this->addExternalJs($this->GetFolder() . '/main.js');

try {
    Extension::load('report.js.dashboard');
    Extension::load("ui.buttons");
} catch (LoaderException $e) {
    ShowError($e->getMessage());
}


echo '<div class="devino-filter"><div>';
$APPLICATION->IncludeComponent(
    'bitrix:main.ui.filter',
    '',
    array(
        'FILTER_ID' => $arResult['FILTER_ID'],
        'FILTER' => $arResult['FILTER_ROWS'],
        'ENABLE_LIVE_SEARCH' => true,
        'ENABLE_LABEL' => true
    ));
echo '</div><div>
<a href="/local/components/devino/report.deal2/formuls_' . strtolower($arParams['TYPE_COMPONENT']) . '.docx" download="" class="ui-btn ui-btn-icon-info ui-btn-primary" style="margin-left: 20px;">Формулы статусов</a>
</div></div>';
?>

<?php if ($arResult['ERRORS_DATA']) : ?>
    <?php foreach ($arResult['ERRORS_DATA'] as $erData): ?>
        <?php ShowError($erData); ?>
    <?php endforeach; ?>
<?php endif; ?>

<div class="report-visualconstructor-dashboard-widget-wrapper report-visualconstructor-dashboard-widget-light"
     style="background-color: rgb(255, 255, 255);">
    <div class="report-visualconstructor-dashboard-widget-head-container">
        <div class="report-visualconstructor-dashboard-widget-head-wrapper">
            <div class="report-visualconstructor-dashboard-widget-title-container"><?= $arResult['NAME_REPORT'] ?></div>
        </div>
    </div>
    <div class="report-visualconstructor-dashboard-widget-content-container">
        <div class="report-visualconstructor-dashboard-widget-content-wrapper">
            <div style="overflow: hidden;">
                <div class="crm-report-column-funnel-wrapper">
                    <table class="crm-report-column-funnel-table">
                        <tbody>
                        <tr class="crm-report-column-funnel-tr crm-report-column-funnel-widget">
                            <td class="crm-report-column-funnel-td ">
                                <div class="crm-report-column-funnel-scale">
                                    <div class="crm-report-column-funnel-scale-box">
                                        <div class="crm-report-column-funnel-scale-item"><?= ($arParams['TYPE_COMPONENT'] == 'DEAL' ? $arResult['DEALS_COUNT']['MAX_COUNT_DEALS'] : ($arParams['TYPE_COMPONENT'] == 'COMPANY' ? $arResult['TRAFFIC_MONEY_DATA_PREPARE']['MAX_STATUS_COUNT'] : 0)) ?></div>
                                        <div class="crm-report-column-funnel-scale-item">0</div>
                                    </div>
                                </div>
                                <div data-role="first-columns-container"
                                     class="crm-report-column-funnel-through-funnel-widget crm-report-column-funnel-through-funnel-widget-columns">
                                    <?php foreach ($arResult['STAGES'] as $stageId => $stageData): ?>
                                        <?php
                                        $curCount = (int)($arParams['TYPE_COMPONENT'] == 'DEAL' ? $arResult['DEALS_COUNT'][$stageId] : ($arParams['TYPE_COMPONENT'] == 'COMPANY' ? $arResult['TRAFFIC_MONEY_DATA_PREPARE']['STATUS_COUNT'][$stageId] : 0)) ?? 0;
                                        $maxCount = (int)($arParams['TYPE_COMPONENT'] == 'DEAL' ? $arResult['DEALS_COUNT']['MAX_COUNT_DEALS'] : ($arParams['TYPE_COMPONENT'] == 'COMPANY' ? $arResult['TRAFFIC_MONEY_DATA_PREPARE']['MAX_STATUS_COUNT'] : 0)) ?? 0;
                                        ?>

                                        <div data-popup="idpopup__<?= $stageId ?>"
                                             data-stage-id-report="<?= $arResult['STAGES_REPORT'][$stageId] ?>"
                                             data-stage-id="<?= $stageId ?>"
                                             style="background-color: <?= $stageData['COLOR'] ?>; height: <?= $this->getComponent()::getPercentHeight((int)$curCount, (int)$maxCount) ?>%;"
                                             onmouseover="OnMouseOver(this)"
                                             onmouseout="onMouseOut_D(this.getAttribute('data-popup'));"
                                             onclick="findClick(this)"
                                            <?= ($arParams['TYPE_COMPONENT'] == 'COMPANY') ? "data-company='" . Json::encode($arResult['TRAFFIC_MONEY_DATA_PREPARE']['STAGES_COMPANY'][$stageId]) . "'" : "" ?>
                                             class="crm-report-column-funnel-through-funnel-widget-item crm-report-column-funnel-through-funnel-widget-item-clickable"></div>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="num-table">
    <span>
        <b><?= $arResult['NAME_ENTITY_REPORT'] ?></b>
        <?= '(Кол-во: ' . ($arParams['TYPE_COMPONENT'] == 'DEAL' ? $arResult['DEALS_COUNT']['MAX_COUNT_DEALS'] ?? 0 : ($arParams['TYPE_COMPONENT'] == 'COMPANY' ? $arResult['TRAFFIC_MONEY_DATA_PREPARE']['MAX_STATUS_COUNT'] ?? 0 : 0)) . ')' ?>
        <?php if ($arResult['FILTER_DATA']['CHANNEL']) {
            echo ', по каналу: ' . $arResult['FILTER_DATA']['CHANNEL'];
        }
        ?>
    </span>

    <table class="nums">
        <?php $dopStyle = ''; ?>

        <?php if (!$arResult['FILTER_DATA']['STATUS_TYPE'] || ($arResult['FILTER_DATA']['STATUS_TYPE'] == 'UF_QTY_AVG3_STATUS')): ?>

            <tr class="subtitle-nums">
                <td colspan="2">План</td>
                <td>% от плана</td>
                <td>Прогноз</td>
                <td>Трафик</td>
            </tr>

            <?php
            $planTrafficFromTotal = false;

            if ($arResult['TYPE_SALES_PLAN']['TYPE'] == 'HEAD_DEPART' &&
                $arResult['SALES_LIST_RESULT'][$arResult['TYPE_SALES_PLAN']['USER_LOGIN']]['TOTAL']['PLAN_TRAFFIC']
            ) {
                $planTrafficFromTotal = true;
            }
            ?>

            <?php foreach ($arResult['TRAFFIC_MONEY_DATA_PREPARE']['SYSTEMS'] as $systemKey => $itemTraffic): ?>
                <tr>
                    <td class="nums-system"><b><?= $systemKey ?></b></td>

                    <?php
                    $planTraffic = 0;

                    if ($planTrafficFromTotal === false) {
                        if ($arResult['FILTER_DATA']['MANAGER_LOGIN']) {
                            $planTraffic = $arResult['SALES_LIST_RESULT'][$arResult['FILTER_DATA']['MANAGER_LOGIN']][$systemKey]['PLAN_TRAFFIC'];
                        } else {
                            if ($arResult['TYPE_SALES_PLAN']['TYPE'] == 'ADMIN') {
                                $planTraffic = $arResult['SALES_LIST_RESULT']['sales_plan'][$systemKey]['PLAN_TRAFFIC'];
                            } elseif ($arResult['TYPE_SALES_PLAN']['TYPE'] == 'HEAD_DEPART' || $arResult['TYPE_SALES_PLAN']['TYPE'] == 'ONLY_USER') {
                                $planTraffic = $arResult['SALES_LIST_RESULT'][$arResult['TYPE_SALES_PLAN']['USER_LOGIN']][$systemKey]['PLAN_TRAFFIC'];
                            }
                        }
                    }
                    ?>


                    <td class="nums-nums"><?= ($planTrafficFromTotal == true && $systemKey == "TOTAL") ? "" : number_format($planTraffic, 0, '.', ' '); ?></td>

                    <?php
                    $number = 0;

                    if ($planTraffic > 0) {
                        $number = round($itemTraffic['TRAFFIC'] * 100 / $planTraffic, 0, PHP_ROUND_HALF_EVEN);

                        if ($number == -0) {
                            $number = 0;
                        }
                    }

                    if ($number <= 0) {
                        $classCss = 'red-percent';
                    } else {
                        $classCss = 'green-percent';
                    }
                    ?>

                    <td class="nums-percent <?= $classCss ?>"><?= $number . '%' ?></td>
                    <td class="nums-nums"><?= number_format($itemTraffic['TRAFFIC_PROGNOZ'], 0, '.', ' ') ?></td>
                    <td><?= number_format($itemTraffic['TRAFFIC'], 0, '.', ' ') ?></td>
                </tr>
            <?php endforeach; ?>

            <?php $dopStyle = 'class="pb25"'; ?>
        <?php endif; ?>

        <?php if (!$arResult['FILTER_DATA']['STATUS_TYPE'] || ($arResult['FILTER_DATA']['STATUS_TYPE'] == 'UF_PROFIT_AVG3_STATUS')): ?>

            <tr>
                <td colspan="5" <?= $dopStyle ?>></td>
            </tr>

            <?php
            $planProfitFromTotal = false;

            if ($arResult['TYPE_SALES_PLAN']['TYPE'] == 'HEAD_DEPART' &&
                $arResult['SALES_LIST_RESULT'][$arResult['TYPE_SALES_PLAN']['USER_LOGIN']]['TOTAL']['PLAN_PROFIT']
            ) {
                $planProfitFromTotal = true;
            }
            ?>

            <tr class="subtitle-nums mt25">
                <td colspan="2">План</td>
                <td>% от плана</td>
                <td>Прогноз</td>
                <td>Профит</td>
            </tr>

            <?php foreach ($arResult['TRAFFIC_MONEY_DATA_PREPARE']['SYSTEMS'] as $systemKey => $itemTraffic): ?>
                <tr>
                    <td class="nums-system"><b><?= $systemKey ?></b></td>
                    <?php
                    $planProfit = 0;

                    if ($planProfitFromTotal === false) {
                        if ($arResult['FILTER_DATA']['MANAGER_LOGIN']) {
                            $planProfit = $arResult['SALES_LIST_RESULT'][$arResult['FILTER_DATA']['MANAGER_LOGIN']][$systemKey]['PLAN_PROFIT'];
                        } else {
                            if ($arResult['TYPE_SALES_PLAN']['TYPE'] == 'ADMIN') {
                                $planProfit = $arResult['SALES_LIST_RESULT']['sales_plan'][$systemKey]['PLAN_PROFIT'];
                            } elseif ($arResult['TYPE_SALES_PLAN']['TYPE'] == 'HEAD_DEPART' || $arResult['TYPE_SALES_PLAN']['TYPE'] == 'ONLY_USER') {
                                $planProfit = $arResult['SALES_LIST_RESULT'][$arResult['TYPE_SALES_PLAN']['USER_LOGIN']][$systemKey]['PLAN_PROFIT'];
                            }
                        }
                    } else {
                        $planProfit = $arResult['SALES_LIST_RESULT'][$arResult['TYPE_SALES_PLAN']['USER_LOGIN']]['TOTAL']['PLAN_PROFIT'];
                    }
                    ?>

                    <td class="nums-nums"><?= ($planProfitFromTotal == true && ($systemKey != "TOTAL")) ? "" : number_format($planProfit, 0, '.', ' ') . ' руб.'; ?></td>

                    <?php
                    $number = 0;

                    if ($planProfit > 0) {
                        $number = round($itemTraffic['PROFIT'] * 100 / $planProfit, 0, PHP_ROUND_HALF_EVEN);

                        if ($number == -0) {
                            $number = 0;
                        }
                    }

                    if ($number <= 0) {
                        $classCss = 'red-percent';
                    } else {
                        $classCss = 'green-percent';
                    }
                    ?>

                    <td class="nums-percent <?= $classCss ?>"><?= $number . '%' ?></td>
                    <td class="nums-nums"><?= (number_format($itemTraffic['PROFIT_PROGNOZ'], 0, '.', ' ') ?? 0) . ' руб.' ?></td>
                    <td><?= (number_format($itemTraffic['PROFIT'], 0, '.', ' ') ?? 0) . ' руб.' ?></td>
                </tr>

            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ((!$arResult['FILTER_DATA']['STATUS_TYPE'] || ($arResult['FILTER_DATA']['STATUS_TYPE'] == 'UF_SELLAMOUNT_AVG3_STATUS')) && $arParams['TYPE_COMPONENT'] == 'COMPANY'): ?>

            <tr>
                <td colspan="5" <?= $dopStyle ?>></td>
            </tr>

            <?php
            $planAmountFromTotal = false;

            if ($arResult['TYPE_SALES_PLAN']['TYPE'] == 'HEAD_DEPART' &&
                $arResult['SALES_LIST_RESULT'][$arResult['TYPE_SALES_PLAN']['USER_LOGIN']]['TOTAL']['PLAN_AMOUNT']
            ) {
                $planAmountFromTotal = true;
            }
            ?>

            <tr class="subtitle-nums mt25">
                <td colspan="2">План</td>
                <td>% от плана</td>
                <td>Прогноз</td>
                <td>Выручка</td>
            </tr>

            <?php foreach ($arResult['TRAFFIC_MONEY_DATA_PREPARE']['SYSTEMS'] as $systemKey => $itemTraffic): ?>
                <tr>
                    <td class="nums-system"><b><?= $systemKey ?></b></td>
                    <?php
                    $planAmount = 0;

                    if ($planAmountFromTotal === false) {
                        if ($arResult['FILTER_DATA']['MANAGER_LOGIN']) {
                            $planAmount = $arResult['SALES_LIST_RESULT'][$arResult['FILTER_DATA']['MANAGER_LOGIN']][$systemKey]['PLAN_AMOUNT'];
                        } else {
                            if ($arResult['TYPE_SALES_PLAN']['TYPE'] == 'ADMIN') {
                                $planAmount = $arResult['SALES_LIST_RESULT']['sales_plan'][$systemKey]['PLAN_AMOUNT'];
                            } elseif ($arResult['TYPE_SALES_PLAN']['TYPE'] == 'HEAD_DEPART' || $arResult['TYPE_SALES_PLAN']['TYPE'] == 'ONLY_USER') {
                                $planAmount = $arResult['SALES_LIST_RESULT'][$arResult['TYPE_SALES_PLAN']['USER_LOGIN']][$systemKey]['PLAN_AMOUNT'];
                            }
                        }
                    } else {
                        $planAmount = $arResult['SALES_LIST_RESULT'][$arResult['TYPE_SALES_PLAN']['USER_LOGIN']]['TOTAL']['PLAN_AMOUNT'];
                    }
                    ?>

                    <td class="nums-nums"><?= ($planAmountFromTotal == true && ($systemKey != "TOTAL")) ? "" : number_format($planAmount, 0, '.', ' ') . ' руб.'; ?></td>

                    <?php
                    $number = 0;

                    if ($planAmount > 0) {
                        $number = round($itemTraffic['AMOUNT'] * 100 / $planAmount, 0, PHP_ROUND_HALF_EVEN);

                        if ($number == -0) {
                            $number = 0;
                        }
                    }

                    if ($number <= 0) {
                        $classCss = 'red-percent';
                    } else {
                        $classCss = 'green-percent';
                    }
                    ?>

                    <td class="nums-percent <?= $classCss ?>"><?= $number . '%' ?></td>
                    <td class="nums-nums"><?= (number_format($itemTraffic['AMOUNT_PROGNOZ'], 0, '.', ' ') ?? 0) . ' руб.' ?></td>
                    <td><?= (number_format($itemTraffic['AMOUNT'], 0, '.', ' ') ?? 0) . ' руб.' ?></td>
                </tr>

            <?php endforeach; ?>
        <?php endif; ?>
    </table>

</div>

<?php foreach ($arResult['STAGES'] as $idStage => $itemStage) : ?>

    <div class="popup-window popup-windowGrapch" id="widget-column-funnel-information-popup"
         data-popup-id="idpopup__<?= $idStage ?>"
         style="visibility: hidden; position: absolute; width: 400px; z-index: 1000; padding: 0px;">
        <div class="popup-window-content" style="padding: 0px;">
            <div class="crm-report-column-funnel-modal crm-report-column-funnel-modal-hidden"
                 style="border-color: <?= $itemStage['COLOR'] ?>; display: block;">
                <div class="crm-report-column-funnel-modal-head">
                    <div class="crm-report-column-funnel-modal-title"><?= $itemStage['NAME'] ?>
                        <?php $formStr = $this->getComponent()->getStagesForm()[$idStage]; ?>
                        <?= $formStr ? "({$formStr})" : '' ?>
                    </div>
                </div>
                <div class="crm-report-column-funnel-modal-main">

                    <div class="crm-report-column-funnel-card-info">
                        <div class="crm-report-column-funnel-card-info-item">
                            <div class="crm-report-column-funnel-card-subtitle">профит</div>
                            <div class="crm-report-column-funnel-card-info-value-box">
                                <div class="crm-report-column-funnel-card-info-value"><?= number_format($arResult['TRAFFIC_MONEY_DATA_PREPARE']['STAGES'][$idStage]['PROFIT'], 4, '.', ' ') ?? 0 ?>
                                    руб.
                                </div>
                            </div>
                        </div>

                        <div class="crm-report-column-funnel-card-info-item">
                            <div class="crm-report-column-funnel-card-subtitle">
                                кол-во <?= $arParams['TYPE_COMPONENT'] == 'DEAL' ? 'сделок' : ($arParams['TYPE_COMPONENT'] == 'COMPANY' ? 'компаний' : '') ?></div>
                            <div class="crm-report-column-funnel-card-info-value-box">
                                <div class="crm-report-column-funnel-card-info-value"><?= ($arParams['TYPE_COMPONENT'] == 'DEAL' ? $arResult['DEALS_COUNT'][$idStage] : ($arParams['TYPE_COMPONENT'] == 'COMPANY' ? $arResult['TRAFFIC_MONEY_DATA_PREPARE']['STATUS_COUNT'][$idStage] : 0)) ?? 0 ?>  </div>
                            </div>
                        </div>
                    </div>

                    <div class="crm-report-column-funnel-card-info">
                        <div class="crm-report-column-funnel-card-info-item">
                            <div class="crm-report-column-funnel-card-subtitle">кол-во трафика</div>
                            <div class="crm-report-column-funnel-card-info-value-box">
                                <div class="crm-report-column-funnel-card-info-value"><?= number_format($arResult['TRAFFIC_MONEY_DATA_PREPARE']['STAGES'][$idStage]['TRAFFIC'], 0, '.', ' ') ?></div>
                            </div>
                        </div>

                        <? if ($arParams['TYPE_COMPONENT'] == 'COMPANY'): ?>
                            <div class="crm-report-column-funnel-card-info-item">
                                <div class="crm-report-column-funnel-card-subtitle">
                                    выручка
                                </div>
                                <div class="crm-report-column-funnel-card-info-value-box">
                                    <div class="crm-report-column-funnel-card-info-value">
                                        <?= number_format($arResult['TRAFFIC_MONEY_DATA_PREPARE']['STAGES'][$idStage]['AMOUNT'] ?? 0, 0, '.', ' ') . ' руб.' ?>
                                    </div>
                                </div>
                            </div>
                        <? endif; ?>

                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>


<?php

$tmpUsers['COLUMNS'] = array(
    "Пользователь",
    "План трафика",
    "Прогноз трафика",
    "Количество трафика",
    "План профита (руб.)",
    "Прогноз профит (руб.)",
    "Профит (руб.)"
);

if ($arParams['TYPE_COMPONENT'] == 'COMPANY') {
    $tmpUsers['COLUMNS'] = array_merge($tmpUsers['COLUMNS'], array(
        "План на выручку (руб.)",
        "Прогноз на выручку (руб.)",
        "Выручка (руб.)",
    ));
}

$tmpUsers['COLUMNS'][] = "Количество сделок";

$keyTable = 0;

function setDataNums($keyTable, $arResult, &$tmpUsers, $typeComponent, $type = 'USER', $isInDepUsers = []) {
    foreach ($arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'] as $userLogin => $userNum) {

        $userNumbers = $userNum['TOTAL'];

        if ($type != 'USER' && !in_array($userLogin, $isInDepUsers)) continue;

        if ($arResult['SALES_LIST_RESULT'][$userLogin]['TOTAL']['PLAN_TRAFFIC'] > 0) {
            $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['TOTAL']['PLAN_TRAFFIC'] = number_format($arResult['SALES_LIST_RESULT'][$userLogin]['TOTAL']['PLAN_TRAFFIC'], 0, '.', ' ');

            $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['TOTAL']['TRAFFIC_PERCENT'] = 0;
            if ($arResult['SALES_LIST_RESULT'][$userLogin]['TOTAL']['PLAN_TRAFFIC']) {
                $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['TOTAL']['TRAFFIC_PERCENT'] = round($userNumbers['TRAFFIC'] * 100 / $arResult['SALES_LIST_RESULT'][$userLogin]['TOTAL']['PLAN_TRAFFIC'], 0, PHP_ROUND_HALF_EVEN);
            }
        } else {
            $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['TOTAL']['PLAN_TRAFFIC'] = number_format($arResult['SALES_LIST_RESULT'][$userLogin]['ALL_PLAN_TRAFFIC'], 0, '.', ' ');

            $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['TOTAL']['TRAFFIC_PERCENT'] = 0;
            if ($arResult['SALES_LIST_RESULT'][$userLogin]['ALL_PLAN_TRAFFIC']) {
                $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['TOTAL']['TRAFFIC_PERCENT'] = round($userNumbers['TRAFFIC'] * 100 / $arResult['SALES_LIST_RESULT'][$userLogin]['ALL_PLAN_TRAFFIC'], 0, PHP_ROUND_HALF_EVEN);
            }
        }

        $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['TOTAL']['PROGNOZ_TRAFFIC'] = number_format($userNumbers['TRAFFIC_PROGNOZ'], 0, '.', ' ');
        $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['TOTAL']['TRAFFIC'] = number_format($userNumbers['TRAFFIC'], 0, '.', ' ');

        if ($arResult['SALES_LIST_RESULT'][$userLogin]['TOTAL']['PLAN_PROFIT'] > 0) {
            $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['TOTAL']['PLAN_PROFIT'] = number_format($arResult['SALES_LIST_RESULT'][$userLogin]['TOTAL']['PLAN_PROFIT'], 0, '.', ' ');

            $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['TOTAL']['PROFIT_PERCENT'] = 0;
            if ($arResult['SALES_LIST_RESULT'][$userLogin]['TOTAL']['PLAN_PROFIT']) {
                $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['TOTAL']['PROFIT_PERCENT'] = round($userNumbers['PROFIT'] * 100 / $arResult['SALES_LIST_RESULT'][$userLogin]['TOTAL']['PLAN_PROFIT'], 0, PHP_ROUND_HALF_EVEN);
            }
        } else {
            $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['TOTAL']['PLAN_PROFIT'] = number_format($arResult['SALES_LIST_RESULT'][$userLogin]['ALL_PLAN_PROFIT'], 0, '.', ' ');

            $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['TOTAL']['PROFIT_PERCENT'] = 0;
            if ($arResult['SALES_LIST_RESULT'][$userLogin]['ALL_PLAN_PROFIT']) {
                $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['TOTAL']['PROFIT_PERCENT'] = round($userNumbers['PROFIT'] * 100 / $arResult['SALES_LIST_RESULT'][$userLogin]['ALL_PLAN_PROFIT'], 0, PHP_ROUND_HALF_EVEN);
            }
        }

        $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['TOTAL']['PROGNOZ_PROFIT'] = number_format($userNumbers['PROFIT_PROGNOZ'], 0, '.', ' ');
        $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['TOTAL']['PROFIT'] = number_format($userNumbers['PROFIT'], 0, '.', ' ');

        if ($typeComponent == 'COMPANY') {
            if ($arResult['SALES_LIST_RESULT'][$userLogin]['TOTAL']['PLAN_AMOUNT'] > 0) {
                $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['TOTAL']['PLAN_AMOUNT'] = number_format($arResult['SALES_LIST_RESULT'][$userLogin]['TOTAL']['PLAN_AMOUNT'], 0, '.', ' ');

                $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['TOTAL']['AMOUNT_PERCENT'] = 0;
                if ($arResult['SALES_LIST_RESULT'][$userLogin]['TOTAL']['PLAN_AMOUNT']) {
                    $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['TOTAL']['AMOUNT_PERCENT'] = round($userNumbers['AMOUNT'] * 100 / $arResult['SALES_LIST_RESULT'][$userLogin]['TOTAL']['PLAN_AMOUNT'], 0, PHP_ROUND_HALF_EVEN);
                }
            } else {
                $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['TOTAL']['PLAN_AMOUNT'] = number_format($arResult['SALES_LIST_RESULT'][$userLogin]['ALL_PLAN_AMOUNT'], 0, '.', ' ');

                $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['TOTAL']['AMOUNT_PERCENT'] = 0;
                if ($arResult['SALES_LIST_RESULT'][$userLogin]['ALL_PLAN_AMOUNT']) {
                    $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['TOTAL']['AMOUNT_PERCENT'] = round($userNumbers['AMOUNT'] * 100 / $arResult['SALES_LIST_RESULT'][$userLogin]['ALL_PLAN_AMOUNT'], 0, PHP_ROUND_HALF_EVEN);
                }
            }

            $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['TOTAL']['PROGNOZ_AMOUNT'] = number_format($userNumbers['AMOUNT_PROGNOZ'], 0, '.', ' ');
            $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['TOTAL']['AMOUNT'] = number_format($userNumbers['AMOUNT'], 0, '.', ' ');
        }


        $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['TOTAL']['COUNT_DEAL'] = (int)$arResult['DEALS_COUNT']['USERS'][$userLogin];

        //записываем тотал по всем отделам
        if ($arResult['TYPE_SALES_PLAN']['TYPE'] == 'ADMIN' || $arResult['TYPE_SALES_PLAN']['TYPE'] == 'HEAD_DEPART') {
            if (in_array($userLogin, array_keys($arResult['DEPARTMENT_STRUCTURE']))) {
                $tmpUsers["ALL_NUMS"]['PROGNOZ_TRAFFIC'] += $userNumbers['TRAFFIC_PROGNOZ'];
                $tmpUsers["ALL_NUMS"]['TRAFFIC'] += $userNumbers['TRAFFIC'];
                $tmpUsers["ALL_NUMS"]['PROFIT'] += $userNumbers['PROFIT'];
                $tmpUsers["ALL_NUMS"]['PROGNOZ_PROFIT'] += $userNumbers['PROFIT_PROGNOZ'];
                $tmpUsers["ALL_NUMS"]['AMOUNT'] += $userNumbers['AMOUNT'];
                $tmpUsers["ALL_NUMS"]['PROGNOZ_AMOUNT'] += $userNumbers['AMOUNT_PROGNOZ'];
            }
        }


        $systems = array_keys($arResult['TRAFFIC_MONEY_DATA_PREPARE']['SYSTEMS']);

        foreach ($systems as $keySystem) {
            if ($keySystem == "TOTAL") continue;

            $userTraffic = $userNum[$keySystem];


            if (!$arResult['SALES_LIST_RESULT'][$userLogin]['TOTAL']['PLAN_TRAFFIC']) {
                $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['SYSTEMS'][$keySystem]['PLAN_TRAFFIC'] = number_format($arResult['SALES_LIST_RESULT'][$userLogin][$keySystem]['PLAN_TRAFFIC'], 0, '.', ' ');

                $number = 0;
                if ($arResult['SALES_LIST_RESULT'][$userLogin][$keySystem]['PLAN_TRAFFIC']) {
                    $number = ($arResult['SALES_LIST_RESULT'][$userLogin][$keySystem]['PLAN_TRAFFIC']) ? round($userTraffic['TRAFFIC'] * 100 / $arResult['SALES_LIST_RESULT'][$userLogin][$keySystem]['PLAN_TRAFFIC'], 0, PHP_ROUND_HALF_EVEN) : 0;

                    if ($number == -0) {
                        $number = 0;
                    }
                }

                $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['SYSTEMS'][$keySystem]['TRAFFIC_PERCENT'] = $number;
            } else {
                $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['SYSTEMS'][$keySystem]['PLAN_TRAFFIC'] = "-";
                $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['SYSTEMS'][$keySystem]['TRAFFIC_PERCENT'] = 0;
            }

            $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['SYSTEMS'][$keySystem]['PROGNOZ_TRAFFIC'] = number_format($userTraffic['TRAFFIC_PROGNOZ'], 0, '.', ' ');
            $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['SYSTEMS'][$keySystem]['TRAFFIC'] = number_format($userTraffic['TRAFFIC'], 0, '.', ' ');

            if (!$arResult['SALES_LIST_RESULT'][$userLogin]['TOTAL']['PLAN_PROFIT']) {
                $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['SYSTEMS'][$keySystem]['PLAN_PROFIT'] = number_format($arResult['SALES_LIST_RESULT'][$userLogin][$keySystem]['PLAN_PROFIT'], 0, '.', ' ');

                $number = 0;
                if ($arResult['SALES_LIST_RESULT'][$userLogin][$keySystem]['PLAN_PROFIT']) {
                    $number = ($arResult['SALES_LIST_RESULT'][$userLogin][$keySystem]['PLAN_PROFIT']) ? round($userTraffic['PROFIT'] * 100 / $arResult['SALES_LIST_RESULT'][$userLogin][$keySystem]['PLAN_PROFIT'], 0, PHP_ROUND_HALF_EVEN) : 0;

                    if ($number == -0) {
                        $number = 0;
                    }
                }

                $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['SYSTEMS'][$keySystem]['PROFIT_PERCENT'] = $number;
            } else {
                $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['SYSTEMS'][$keySystem]['PLAN_PROFIT'] = "-";
                $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['SYSTEMS'][$keySystem]['PROFIT_PERCENT'] = 0;
            }

            $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['SYSTEMS'][$keySystem]['PROGNOZ_PROFIT'] = number_format($userTraffic['PROFIT_PROGNOZ'], 0, '.', ' ');
            $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['SYSTEMS'][$keySystem]['PROFIT'] = number_format($userTraffic['PROFIT'], 0, '.', ' ');


            if ($typeComponent == 'COMPANY') {
                if (!$arResult['SALES_LIST_RESULT'][$userLogin]['TOTAL']['PLAN_AMOUNT']) {
                    $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['SYSTEMS'][$keySystem]['PLAN_AMOUNT'] = number_format($arResult['SALES_LIST_RESULT'][$userLogin][$keySystem]['PLAN_AMOUNT'], 0, '.', ' ');

                    $number = 0;
                    if ($arResult['SALES_LIST_RESULT'][$userLogin][$keySystem]['PLAN_AMOUNT']) {
                        $number = ($arResult['SALES_LIST_RESULT'][$userLogin][$keySystem]['PLAN_AMOUNT']) ? round($userTraffic['AMOUNT'] * 100 / $arResult['SALES_LIST_RESULT'][$userLogin][$keySystem]['PLAN_AMOUNT'], 0, PHP_ROUND_HALF_EVEN) : 0;

                        if ($number == -0) {
                            $number = 0;
                        }
                    }

                    $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['SYSTEMS'][$keySystem]['AMOUNT_PERCENT'] = $number;
                } else {
                    $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['SYSTEMS'][$keySystem]['PLAN_AMOUNT'] = "-";
                    $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['SYSTEMS'][$keySystem]['AMOUNT_PERCENT'] = 0;
                }

                $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['SYSTEMS'][$keySystem]['PROGNOZ_AMOUNT'] = number_format($userTraffic['AMOUNT_PROGNOZ'], 0, '.', ' ');
                $tmpUsers["TABLES"][$keyTable]['NUMS'][$userLogin]['SYSTEMS'][$keySystem]['AMOUNT'] = number_format($userTraffic['AMOUNT'], 0, '.', ' ');
            }
        }
    }

    if (!$tmpUsers["TABLES"][$keyTable]['NUMS']) {
        unset($tmpUsers["TABLES"][$keyTable]);
    }
}

if ($arResult['TYPE_SALES_PLAN']['TYPE'] == 'ADMIN' || $arResult['TYPE_SALES_PLAN']['TYPE'] == 'HEAD_DEPART') {

    $headDepartsLogin = array_keys($arResult['DEPARTMENT_STRUCTURE']);

    foreach ($headDepartsLogin as $departHead) {
        if (isset($arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'][$departHead])) {
            $tmpKey = $departHead;
            $tmpData = $arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'][$departHead];

            unset($arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'][$departHead]);

            $arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'] = array($tmpKey => $tmpData) + $arResult['TRAFFIC_MONEY_DATA_PREPARE']['USERS'];

        }
    }

    foreach ($arResult['DEPARTMENT_STRUCTURE'] as $departmentHeadLogin => $departmentsData) {

        foreach ($departmentsData as $depId => $departmentData) {

            $currentUsers = $departmentData['USERS'];
            $tmpUsers["TABLES"][$keyTable]['TABLE_NAME'] = "Эффективность работы менеджеров ({$departmentData['DEPART_DATA']['NAME']})";

            setDataNums($keyTable, $arResult, $tmpUsers, $arParams['TYPE_COMPONENT'], 'OTHER', $currentUsers);

            $keyTable++;
        }
    }
} else {
    $tmpUsers["TABLES"][$keyTable]['TABLE_NAME'] = "Эффективность работы менеджера";

    setDataNums($keyTable, $arResult, $tmpUsers, $arParams['TYPE_COMPONENT']);
}
?>

<?php foreach ($tmpUsers['TABLES'] as $table): ?>
    <div class="wrap-manager-stat">

        <div class="wrap-manager-stat-title">
            <h3 style="font-weight: normal;"><?= $table['TABLE_NAME'] ?></h3>
        </div>

        <div class="wrap-manager-stat-table">
            <table class="main-grid-table">
                <thead class="main-grid-header">
                <tr class="main-grid-row-head">
                    <?php foreach ($tmpUsers['COLUMNS'] as $columnName): ?>
                        <th class="main-grid-cell-head main-grid-cell-left main-grid-col-no-sortable">
                            <span class="main-grid-cell-head-container">
                                <span class="main-grid-head-title"><?= $columnName ?></span>
                            </span>
                        </th>
                    <?php endforeach; ?>
                </tr>
                </thead>

                <tbody>
                <?php foreach ($table['NUMS'] as $userLogin => $userNumRow): ?>
                    <tr class="main-grid-row main-grid-row-body">
                        <?php
                        $userData = $arResult['USERS_DATA'][$userLogin];
                        if (!$userData) continue;
                        ?>

                        <td class="main-grid-cell main-grid-cell-left" <?= $arResult['DEPARTMENT_STRUCTURE'][$userLogin] ? 'style="background-color: #bfbbbb; font-weight: 600;"' : '' ?>>
                        <span class="main-grid-cell-content">
                            <a href="/company/personal/user/<?= $userData['ID'] ?>/" target="_top">
                                <div class="report-widget-grid-grouping">
                                        <div class="ui-icon ui-icon-common-user report-widget-grid-grouping-icon">
                                            <i style="background-image: url('<?= str_replace(' ', '%20', CFile::GetPath($userData['PHOTO'])) ?>')"></i>
                                        </div>
                                    <?php
                                    $dopStr = "";
                                    if ($arResult['DEPARTMENT_STRUCTURE'][$userLogin]) {
                                        $dopStr .= " (по отделу)";
                                    }
                                    ?>
                                    <div class="report-widget-grid-grouping-name"><?= $userData['NAME'] . $dopStr ?></div>
                                </div>
                            </a>
                        </span>
                        </td>

                        <?php
                        $totalNums = $userNumRow['TOTAL'];
                        ?>
                        <td class="main-grid-cell main-grid-cell-left <?= $arResult['DEPARTMENT_STRUCTURE'][$userLogin] ? 'main-row' : '' ?>">
                            <span class="main-grid-cell-content"><?= $totalNums['PLAN_TRAFFIC'] ?>
                                <?php
                                $classCss = "";

                                if ($totalNums['TRAFFIC_PERCENT'] > 0) {
                                    $classCss = "green-percent";
                                } else {
                                    $classCss = 'red-percent';
                                }
                                ?>
                                <span class="<?= $classCss ?>"><?= '(' . $totalNums['TRAFFIC_PERCENT'] . '%)' ?></span>
                            </span>
                        </td>

                        <td class="main-grid-cell main-grid-cell-left <?= $arResult['DEPARTMENT_STRUCTURE'][$userLogin] ? 'main-row' : '' ?>">
                        <span class="main-grid-cell-content">
                            <?= $totalNums['PROGNOZ_TRAFFIC'] ?>
                        </span>
                        </td>

                        <td class="main-grid-cell main-grid-cell-left <?= $arResult['DEPARTMENT_STRUCTURE'][$userLogin] ? 'main-row' : '' ?>">
                        <span class="main-grid-cell-content">
                            <?= $totalNums['TRAFFIC'] ?>
                        </span>
                        </td>

                        <td class="main-grid-cell main-grid-cell-left <?= $arResult['DEPARTMENT_STRUCTURE'][$userLogin] ? 'main-row' : '' ?>">
                        <span class="main-grid-cell-content"><?= $totalNums['PLAN_PROFIT'] ?>
                            <?php
                            $classCss = "";

                            if ($totalNums['TRAFFIC_PERCENT'] > 0) {
                                $classCss = "green-percent";
                            } else {
                                $classCss = 'red-percent';
                            }
                            ?>
                            <span class="<?= $classCss ?>"><?= '(' . $totalNums['PROFIT_PERCENT'] . '%)' ?></span>
                        </span>
                        </td>

                        <td class="main-grid-cell main-grid-cell-left <?= $arResult['DEPARTMENT_STRUCTURE'][$userLogin] ? 'main-row' : '' ?>">
                        <span class="main-grid-cell-content">
                            <?= $totalNums['PROGNOZ_PROFIT'] ?>
                        </span>
                        </td>

                        <td class="main-grid-cell main-grid-cell-left <?= $arResult['DEPARTMENT_STRUCTURE'][$userLogin] ? 'main-row' : '' ?>">
                        <span class="main-grid-cell-content">
                            <?= $totalNums['PROFIT'] ?>
                        </span>
                        </td>

                        <?php if ($arParams['TYPE_COMPONENT'] == 'COMPANY'): ?>
                            <td class="main-grid-cell main-grid-cell-left <?= $arResult['DEPARTMENT_STRUCTURE'][$userLogin] ? 'main-row' : '' ?>">
                        <span class="main-grid-cell-content"><?= $totalNums['PLAN_AMOUNT'] ?>
                            <?php
                            $classCss = "";

                            if ($totalNums['AMOUNT_PERCENT'] > 0) {
                                $classCss = "green-percent";
                            } else {
                                $classCss = 'red-percent';
                            }
                            ?>
                            <span class="<?= $classCss ?>"><?= '(' . $totalNums['AMOUNT_PERCENT'] . '%)' ?></span>
                        </span>
                            </td>

                            <td class="main-grid-cell main-grid-cell-left <?= $arResult['DEPARTMENT_STRUCTURE'][$userLogin] ? 'main-row' : '' ?>">
                        <span class="main-grid-cell-content">
                            <?= $totalNums['PROGNOZ_AMOUNT'] ?>
                        </span>
                            </td>

                            <td class="main-grid-cell main-grid-cell-left <?= $arResult['DEPARTMENT_STRUCTURE'][$userLogin] ? 'main-row' : '' ?>">
                        <span class="main-grid-cell-content">
                            <?= $totalNums['AMOUNT'] ?>
                        </span>
                            </td>
                        <?php endif; ?>

                        <td class="main-grid-cell main-grid-cell-left <?= $arResult['DEPARTMENT_STRUCTURE'][$userLogin] ? 'main-row' : '' ?>">
                        <span class="main-grid-cell-content">
                            <?= $totalNums['COUNT_DEAL'] ?>
                        </span>
                        </td>
                    </tr>

                    <?php foreach ($userNumRow['SYSTEMS'] as $keySystem => $systemNums): ?>
                        <tr class="dop-info">

                            <td class="platform-container">
                                <span><?= $this->getComponent()::PLATFORMS_NAME[$keySystem] ?></span>
                            </td>

                            <td class="plan-container">
                                <span class="plan-number"><?= $systemNums['PLAN_TRAFFIC'] ?></span>

                                <?php
                                $classCss = 'red-percent';
                                if ($systemNums['TRAFFIC_PERCENT'] > 0) $classCss = 'green-percent';
                                ?>

                                <span class="<?= $classCss ?>"><?= '(' . $systemNums['TRAFFIC_PERCENT'] . '%)' ?></span>
                            </td>

                            <td class="prognoz-container">
                                <span><?= $systemNums['PROGNOZ_TRAFFIC'] ?></span>
                            </td>

                            <td class="traffic-container">
                                <span><?= $systemNums['TRAFFIC'] ?></span>
                            </td>

                            <td class="plan-container">
                                <span class="plan-number"><?= $systemNums['PLAN_PROFIT'] ?></span>

                                <?php
                                $classCss = 'red-percent';
                                if ($systemNums['PROFIT_PERCENT'] > 0) $classCss = 'green-percent';
                                ?>

                                <span class="<?= $classCss ?>"><?= '(' . $systemNums['PROFIT_PERCENT'] . '%)' ?></span>
                            </td>

                            <td class="prognoz-container">
                                <span><?= $systemNums['PROGNOZ_PROFIT'] ?></span>
                            </td>

                            <td class="profit-container">
                                <span><?= $systemNums['PROFIT'] ?></span>
                            </td>

                            <? if ($arParams['TYPE_COMPONENT'] == 'COMPANY'): ?>
                                <td class="plan-container">
                                    <span class="plan-number"><?= $systemNums['PLAN_AMOUNT'] ?></span>

                                    <?php
                                    $classCss = 'red-percent';
                                    if ($systemNums['AMOUNT_PERCENT'] > 0) $classCss = 'green-percent';
                                    ?>

                                    <span class="<?= $classCss ?>"><?= '(' . $systemNums['AMOUNT_PERCENT'] . '%)' ?></span>
                                </td>

                                <td class="prognoz-container">
                                    <span><?= $systemNums['PROGNOZ_AMOUNT'] ?></span>
                                </td>

                                <td class="profit-container">
                                    <span><?= $systemNums['AMOUNT'] ?></span>
                                </td>
                            <? endif; ?>
                        </tr>

                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endforeach; ?>


<?php if ($arResult['TYPE_SALES_PLAN']['TYPE'] != 'ONLY_USER' && $tmpUsers['ALL_NUMS']): ?>
    <div class="wrap-manager-stat">

        <div class="wrap-manager-stat-title">
            <h3 style="font-weight: normal;">Всего</h3>
        </div>

        <div class="wrap-manager-stat-table">
            <table class="main-grid-table">
                <thead class="main-grid-header">
                <tr class="main-grid-row-head">
                    <th class="main-grid-cell-head main-grid-cell-left main-grid-col-no-sortable">
                            <span class="main-grid-cell-head-container">
                                <span class="main-grid-head-title">Прогноз трафика</span>
                            </span>
                    </th>
                    <th class="main-grid-cell-head main-grid-cell-left main-grid-col-no-sortable">
                            <span class="main-grid-cell-head-container">
                                <span class="main-grid-head-title">Количество трафика</span>
                            </span>
                    </th>
                    <th class="main-grid-cell-head main-grid-cell-left main-grid-col-no-sortable">
                            <span class="main-grid-cell-head-container">
                                <span class="main-grid-head-title">Прогноз профит (руб.)</span>
                            </span>
                    </th>
                    <th class="main-grid-cell-head main-grid-cell-left main-grid-col-no-sortable">
                            <span class="main-grid-cell-head-container">
                                <span class="main-grid-head-title">Профит (руб.)</span>
                            </span>
                    </th>
                    <? if ($arParams['TYPE_COMPONENT'] == 'COMPANY'): ?>
                        <th class="main-grid-cell-head main-grid-cell-left main-grid-col-no-sortable">
                            <span class="main-grid-cell-head-container">
                                <span class="main-grid-head-title">Прогноз на выручку (руб.)</span>
                            </span>
                        </th>
                        <th class="main-grid-cell-head main-grid-cell-left main-grid-col-no-sortable">
                            <span class="main-grid-cell-head-container">
                                <span class="main-grid-head-title">Выручка (руб.)</span>
                            </span>
                        </th>
                    <? endif; ?>
                </tr>
                </thead>
                <tbody>
                <tr class="main-grid-row main-grid-row-body">
                    <td class="main-grid-cell main-grid-cell-left">
                    <span class="main-grid-cell-content">
                        <?= number_format($tmpUsers['ALL_NUMS']['PROGNOZ_TRAFFIC'], 0, '.', ' ') ?>
                    </span>
                    </td>

                    <td class="main-grid-cell main-grid-cell-left">
                    <span class="main-grid-cell-content">
                        <?= number_format($tmpUsers['ALL_NUMS']['TRAFFIC'], 0, '.', ' ') ?>
                    </span>
                    </td>

                    <td class="main-grid-cell main-grid-cell-left">
                    <span class="main-grid-cell-content">
                        <?= number_format($tmpUsers['ALL_NUMS']['PROGNOZ_PROFIT'], 0, '.', ' ') ?>
                    </span>
                    </td>

                    <td class="main-grid-cell main-grid-cell-left">
                    <span class="main-grid-cell-content">
                        <?= number_format($tmpUsers['ALL_NUMS']['PROFIT'], 0, '.', ' ') ?>
                    </span>
                    </td>

                    <? if ($arParams['TYPE_COMPONENT'] == 'COMPANY'): ?>
                        <td class="main-grid-cell main-grid-cell-left">
                    <span class="main-grid-cell-content">
                        <?= number_format($tmpUsers['ALL_NUMS']['PROGNOZ_AMOUNT'], 0, '.', ' ') ?>
                    </span>
                        </td>

                        <td class="main-grid-cell main-grid-cell-left">
                    <span class="main-grid-cell-content">
                        <?= number_format($tmpUsers['ALL_NUMS']['AMOUNT'], 0, '.', ' ') ?>
                    </span>
                        </td>
                    <? endif; ?>
                </tr>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<script>
    function getParamRequest() {
        let paramRequest = "";

        <?php
        if ($arResult['TYPE_SALES_PLAN']['TYPE'] == 'ONLY_USER') {
            echo 'paramRequest = ' . Json::encode(array_merge($arResult['FILTER_DATA'], array('MANAGER_LOGIN' => $arResult['TYPE_SALES_PLAN']['USER_LOGIN'], 'USER_ID' => $arResult['TYPE_SALES_PLAN']['USER_ID'])));

        } elseif ($arResult['TYPE_SALES_PLAN']['TYPE'] == 'HEAD_DEPART' || $arResult['TYPE_SALES_PLAN']['TYPE'] == 'ADMIN') {
            if (!$arResult['FILTER_DATA']['MANAGER_LOGIN']) {
                if ($arResult['TYPE_SALES_PLAN']['TYPE'] == 'ADMIN') {
                    echo 'paramRequest = ' . Json::encode($arResult['FILTER_DATA']);
                } else {
                    echo 'paramRequest = ' . Json::encode(array_merge($arResult['FILTER_DATA'], array('MANAGER_LOGIN' => $arResult['TYPE_SALES_PLAN']['WHO_IN_DEP'])));
                }
            } else {
                $filter = $arResult['FILTER_DATA'];

                unset($filter['USER_ID'], $filter['MANAGER_LOGIN']);

                if (isset($arResult['DEPARTMENT_STRUCTURE'][$arResult['FILTER_DATA']['MANAGER_LOGIN']])) {

                    foreach ($arResult['DEPARTMENT_STRUCTURE'][$arResult['FILTER_DATA']['MANAGER_LOGIN']] as $departs) {
                        $filter['MANAGER_LOGIN'] = array_merge($filter['MANAGER_LOGIN'] ?? [], $departs['USERS']);
                    }

                } else {
                    $filter['MANAGER_LOGIN'] = $arResult['FILTER_DATA']['MANAGER_LOGIN'];
                }

                echo 'paramRequest = ' . Json::encode($filter);
            }
        }
        ?>

        return paramRequest;
    }

</script>

<?php if ($arParams['TYPE_COMPONENT'] == 'DEAL'): ?>
    <script>
        function findClick(stageEl) {
            BX.ready(function () {
                let stageId = stageEl.getAttribute('data-stage-id'),
                    stageIdReport = stageEl.getAttribute('data-stage-id-report'),
                    countDeals = JSON.parse('<?= Json::encode($arResult['DEALS_COUNT']) ?>');

                if (countDeals[stageId] > 0) {
                    let xhr = new XMLHttpRequest(),
                        formData = new FormData();

                    formData.append('paramRequest', JSON.stringify(getParamRequest()));
                    formData.append('stageId', stageIdReport);
                    formData.append('action', 'setListDealFilter');
                    formData.append('typeComponent', '<?= $arParams['TYPE_COMPONENT'] ?>');

                    xhr.open('POST', '<?= $componentPath ?>/ajax.php', true);
                    xhr.onreadystatechange = function () {
                        if (xhr.status === 200 && xhr.readyState === 4) {
                            window.open('/report/deal/list/', '_blank');
                        }
                    };

                    xhr.send(formData);
                }
            })
        }
    </script>
<?php elseif ($arParams['TYPE_COMPONENT'] == 'COMPANY'): ?>
    <script>
        function findClick(stageEl) {
            let stageId = stageEl.getAttribute('data-stage-id'),
                countDeals = JSON.parse('<?= Json::encode($arResult['TRAFFIC_MONEY_DATA_PREPARE']['STATUS_COUNT']) ?>');

            if ((stageEl.getAttribute('data-company')) && (countDeals[stageId] > 0)) {
                let xhr = new XMLHttpRequest(),
                    formData = new FormData();

                formData.append('paramRequest', JSON.stringify(getParamRequest()));
                formData.append('typeUser', '<?= $arResult['TYPE_SALES_PLAN']['TYPE'] ?>');
                formData.append('customerIds', stageEl.getAttribute('data-company'));
                formData.append('stageId', stageId);
                formData.append('action', 'setListCompanyFilter');
                formData.append('typeComponent', '<?= $arParams['TYPE_COMPONENT'] ?>');

                xhr.open('POST', '<?= $componentPath ?>/ajax.php', true);
                xhr.onreadystatechange = function () {
                    if (xhr.status === 200 && xhr.readyState === 4) {
                        window.open('/report/company/list/', '__blank');
                    }
                };

                xhr.send(formData);
            }
        }
    </script>
<?php endif; ?>

