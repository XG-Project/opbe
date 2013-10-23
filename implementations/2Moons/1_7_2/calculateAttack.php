<?php

/**
 *  OPBE
 *  Copyright (C) 2013  Jstar
 *
 * This file is part of OPBE.
 * 
 * OPBE is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * OPBE is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with OPBE.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package OPBE
 * @author Jstar <frascafresca@gmail.com>
 * @copyright 2013 Jstar <frascafresca@gmail.com>
 * @license http://www.gnu.org/licenses/ GNU AGPLv3 License
 * @version alpha(2013-2-4)
 * @link https://github.com/jstar88/opbe
 */
define('OPBEPATH', ROOT_PATH . 'includes/libs/opbe/');
include (OPBEPATH . 'utils/includer.php');

define('ID_MIN_SHIPS', 100);
define('ID_MAX_SHIPS', 300);
define('HOME_FLEET', 0);
define('DEFENDERS_WON', 'r');
define('ATTACKERS_WON', 'a');
define('DRAW', 'w');
define('METAL_ID', 901);
define('CRYSTAL_ID', 902);


/**
 * calculateAttack()
 * Calculate the battle using OPBE.
 * 
 * OPBE ,to decrease memory usage, don't save both the initial and end state of fleets in a single round: only the end state is saved.
 * Then OPBE store the first round in BattleReport and don't start it, just to show the fleets before the battle.
 * Also,cause OPBE start the rounds without saving the initial state, the informations about how many shots were fired etc must be asked to the next round.
 * Logically, the last round can't ask the next round because there is not.
 * 
 * @param array &$attackers
 * @param array &$defenders
 * @param mixed $FleetTF
 * @param mixed $DefTF
 * @return array
 */
function calculateAttack(&$attackers, &$defenders, $FleetTF, $DefTF)
{
    $CombatCaps = $GLOBALS['CombatCaps'];
    $pricelist = $GLOBALS['pricelist'];

    /********** BUILDINGS MODELS **********/
    /** Note: we are transform array of data like
     *  fleetID => infos
     *  into object tree structure like
     *  playerGroup -> player -> fleet -> shipType
     */

    //attackers
    $attackerGroupObj = new PlayerGroup();
    foreach ($attackers as $fleetID => $attacker)
    {
        $player = $attacker['player'];
        $attackerPlayerObj = $attackerGroupObj->createPlayerIfNotExist($player['id'], array(), $player['military_tech'], $player['shield_tech'], $player['defence_tech']);
        $attackerFleetObj = new Fleet($fleetID);
        foreach ($attacker['unit'] as $element => $amount)
        {
            if (empty($amount)) continue;
            $fighters = getFighters($element, $amount);
            $attackerFleetObj->add($fighters);
        }
        $attackerPlayerObj->addFleet($attackerFleetObj);
    }
    //defenders
    $defenderGroupObj = new PlayerGroup();
    foreach ($defenders as $fleetID => $defender)
    {
        $player = $defender['player'];
        $defenderPlayerObj = $defenderGroupObj->createPlayerIfNotExist($player['id'], array(), $player['military_tech'], $player['shield_tech'], $player['defence_tech']);
        $defenderFleetObj = getFleet($fleetID);
        foreach ($defender['unit'] as $element => $amount)
        {
	        if (empty($amount)) continue;
            $fighters = getFighters($element, $amount);
            $defenderFleetObj->add($fighters);
        }
        $defenderPlayerObj->addFleet($defenderFleetObj);
    }

    /********** BATTLE ELABORATION **********/
    $opbe = new Battle($attackerGroupObj, $defenderGroupObj);
    $opbe->startBattle();
    $report = $opbe->getReport();

    /********** WHO WON **********/
    if ($report->defenderHasWin())
    {
        $won = DEFENDERS_WON;
    }
    elseif ($report->attackerHasWin())
    {
        $won = ATTACKERS_WON;
    }
    elseif ($report->isAdraw())
    {
        $won = DRAW;
    }
    else
    {
        throw new Exception('problem');
    }

    /********** ROUNDS INFOS **********/

    $ROUND = array();
    $i = 0;
    $lastRound = $report->getLastRoundNumber();
    for (; $i <= $lastRound; $i++)
    {
        // in case of last round, ask for rebuilt defenses. to change rebuils prob see constants/battle_constants.php
        $attackerGroupObj = ($lastRound == $i) ? $report->getAfterBattleAttackers() : $report->getResultAttackersFleetOnRound($i);
        $defenderGroupObj = ($lastRound == $i) ? $report->getAfterBattleDefenders() : $report->getResultDefendersFleetOnRound($i);
        $attInfo = updatePlayers($attackerGroupObj, $attackers);
        $defInfo = updatePlayers($defenderGroupObj, $defenders);
        $ROUND[$i] = roundInfo($report, $attackers, $defenders, $attackerGroupObj, $defenderGroupObj, $i + 1, $attInfo, $defInfo);
    }

    /********** DEBRIS **********/
    //attackers
    $debAtt = $report->getAttackerDebris();
    $debAttMet = $debAtt[0];
    $debAttCry = $debAtt[1];
    //defenders
    $debDef = $report->getDefenderDebris();
    $debDefMet = $debDef[0];
    $debDefCry = $debDef[1];
    //total
    $debris = array('attacker' => array(METAL_ID => $debAttMet, CRYSTAL_ID => $debAttCry), 'defender' => array(METAL_ID => $debDefMet, CRYSTAL_ID => $debDefCry));

    /********** LOST UNITS **********/
    $totalLost = array('attacker' => $report->getTotalAttackersLostUnits(), 'defender' => $report->getTotalDefendersLostUnits());

    /********** RETURNS **********/
    return array(
        'won' => $won,
        'debris' => $debris,
        'rw' => $ROUND,
        'unitLost' => $totalLost);
}


/**
 * roundInfo()
 * Return the info required to fill $ROUND.
 * @param BattleReport $report
 * @param array $attackers
 * @param array $defenders
 * @param PlayerGroup $attackerGroupObj
 * @param PlayerGroup $defenderGroupObj
 * @param int $i
 * @return array
 */
function roundInfo(BattleReport $report, $attackers, $defenders, PlayerGroup $attackerGroupObj, PlayerGroup $defenderGroupObj, $i, $attInfo, $defInfo)
{
    // the last round doesn't has next round, so we not ask for fire etc
    return array(
        'attack' => ($i > $report->getLastRoundNumber()) ? 0 : $report->getAttackersFirePower($i),
        'defense' => ($i > $report->getLastRoundNumber()) ? 0 : $report->getDefendersFirePower($i),
        'defShield' => ($i > $report->getLastRoundNumber()) ? 0 : $report->getDefendersAssorbedDamage($i),
        'attackShield' => ($i > $report->getLastRoundNumber()) ? 0 : $report->getAttachersAssorbedDamage($i),
        'attackers' => $attackers,
        'defenders' => $defenders,
        'attackA' => $attInfo[1],
        'defenseA' => $defInfo[1],
        'infoA' => $attInfo[0],
        'infoD' => $defInfo[0]);
}


/**
 * updatePlayers()
 * Update players array as default 2moons require.
 * OPBE keep the internal array data full to decrease memory size, so a PlayerGroup object don't have data about 
 * empty users(an user is empty when fleets are empty and fleet is empty when the ships count is zero)
 * Instead, the old system require to have also array of zero: to update the array of users, after a round, we must iterate them
 * and check the corrispective OPBE value if empty (functions like "getX()" return "false")  
 * 
 * @param PlayerGroup $playerGroup
 * @param array &$players
 * @return null
 */
function updatePlayers(PlayerGroup $playerGroup, &$players)
{
    $plyArray = array();
    $amountArray = array();
    foreach ($players as $idFleet => $info)
    {
        $shipInfo = $info['unit'];
        $player = $playerGroup->getPlayer($info['player']['id']);
        $fleet = ($player !== false) ? $player->getFleet($idFleet) : false;

        foreach ($shipInfo as $idFighters => $amount)
        {
            if ($fleet !== false) //if after battle still there are some ship types in this fleet
            {
                $fighters = $fleet->getFighters($idFighters);
                if ($fighters !== false) //if there are some ships of this type
                {
                    //used to show life,power and shield of each ships in the report
                    $plyArray[$idFleet][$idFighters] = array(
                        'def' => $fighters->getHull(),
                        'shield' => $fighters->getShield(),
                        'att' => $fighters->getPower());
                    $players[$idFleet]['unit'][$idFighters] = $fighters->getCount();
                }
                else //all ships of this type were destroyed
                {
                    $players[$idFleet]['unit'][$idFighters] = 0;
                }
            }
            else //the fleet is empty, so all ships of this type were destroyed
            {
                $players[$idFleet]['unit'][$idFighters] = 0;
            }

            //initialization
            if (!isset($amountArray[$idFleet]))
            {
                $amountArray[$idFleet] = 0;
            }
            if (!isset($amountArray['total']))
            {
                $amountArray['total'] = 0;
            }
            //increment
            $currentAmount = $players[$idFleet]['unit'][$idFighters];
            $amountArray[$idFleet] = $amountArray[$idFleet] + $currentAmount;
            $amountArray['total'] = $amountArray['total'] + $currentAmount;
        }
        //used to show techs in the report .Empty player not exist in OPBE's result data
        $players[$idFleet]['techs'] = array(
            ($player != false) ? $player->getWeaponsTech() : 0,
            ($player != false) ? $player->getArmourTech() : 0,
            ($player != false) ? $player->getShieldsTech() : 0);
    }
    return array($plyArray, $amountArray);
}


/**
 * getFighters()
 * Choose the correct class type by ID
 * 
 * @param int $id
 * @param int $count
 * @return a Ship or Defense instance
 */
function getFighters($id, $count)
{
    $CombatCaps = $GLOBALS['CombatCaps'];
    $pricelist = $GLOBALS['pricelist'];
    $rf = isset($CombatCaps[$id]['sd']) ? $CombatCaps[$id]['sd'] : 0;
    $shield = $CombatCaps[$id]['shield'];
    $cost = array($pricelist[$id]['cost'][METAL_ID], $pricelist[$id]['cost'][CRYSTAL_ID]);
    $power = $CombatCaps[$id]['attack'];
    if ($id > ID_MIN_SHIPS && $id < ID_MAX_SHIPS)
    {
        return new Ship($id, $count, $rf, $shield, $cost, $power);
    }
    return new Defense($id, $count, $rf, $shield, $cost, $power);
}


/**
 * getFleet()
 * Choose the correct class type by ID
 * 
 * @param int $id
 * @return a Fleet or HomeFleet instance
 */
function getFleet($id)
{
    if ($id == HOME_FLEET)
    {
        return new HomeFleet(HOME_FLEET);
    }
    return new Fleet($id);
}

?>
