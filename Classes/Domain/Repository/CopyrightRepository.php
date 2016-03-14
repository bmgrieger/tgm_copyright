<?php
namespace TGM\TgmCopyright\Domain\Repository;


/***************************************************************
 *
 *  Copyright notice
 *
 *  (c) 2016 Paul Beck <pb@teamgeist-medien.de>, Teamgeist Medien GbR
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;

/**
 * The repository for Copyrights
 */
class CopyrightRepository extends \TYPO3\CMS\Extbase\Persistence\Repository
{

    /**
     * @param string $rootlines
     * @return array|\TYPO3\CMS\Extbase\Persistence\QueryResultInterface
     */
    public function findByRootline($rootlines) {

        if($rootlines!='') {
            $pidClause = ' AND ref.pid IN('.$this->extendPidListByChildren($rootlines,1000).')';
        } else {
            $pidClause = '';
        }

        // First main statement, exclude by all possible exclusion reasons
        $preQuery = $this->createQuery();
        $preQuery->statement('
          SELECT ref.* FROM sys_file_reference AS ref
          LEFT JOIN sys_file AS file ON (file.uid=ref.uid_local)
          LEFT JOIN sys_file_metadata AS meta ON (file.uid=meta.file)
          LEFT JOIN pages AS p ON (ref.pid=p.uid)
          WHERE (ref.copyright IS NOT NULL OR meta.copyright!="")
          AND p.deleted=0 AND p.hidden=0 AND file.missing=0 AND file.uid IS NOT NULL
          AND ref.deleted=0 AND ref.hidden=0 AND ref.t3ver_wsid=0 '. $pidClause);

        $preResults = $preQuery->execute(TRUE);


        // Now check if the foreign record has a endtime field which is expired
        $tableCache = array();
        $finalRecords = array();
        $now = time();

        foreach($preResults as $preResult) {
            if(isset($preResult['tablenames']) && isset($preResult['uid_foreign'])) {
                if(!array_key_exists($preResult['tablenames'],$tableCache)) {
                    $tableCache[$preResult['tablenames']] = $GLOBALS['TYPO3_DB']->admin_get_fields($preResult['tablenames']);
                }
                // TODO: We could include hidden and deleted here, too. But we've to check if it exists before
                if(isset($tableCache[$preResult['tablenames']]['endtime'])) {
                    $foreignRecord = $GLOBALS['TYPO3_DB']->exec_SELECTgetSingleRow('uid,endtime',$preResult['tablenames'],'uid='.$preResult['uid_foreign'].' AND (endtime=0 OR endtime>'.$now.')');
                    if($foreignRecord===FALSE || $foreignRecord===NULL) {
                        // Exlude if nothing found
                        continue;
                    }
                }
                // Add the record to the final select if the foreign record is not expired or does not have a field endtime
                $finalRecords[] = $preResult['uid'];
            }
        }

        // Final select
        $finalQuery = $this->createQuery();
        return $finalQuery->statement('SELECT * FROM sys_file_reference WHERE uid IN('.implode(',',$finalRecords).')')->execute();
    }

    /**
     * Find all ids from given ids and level by Georg Ringer
     * @param string $pidList comma separated list of ids
     * @param int $recursive recursive levels
     * @return string comma separated list of ids
     */
    public static function extendPidListByChildren($pidList = '', $recursive = 0)
    {
        $recursive = (int)$recursive;
        if ($recursive <= 0) {
            return $pidList;
        }
        $queryGenerator = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\QueryGenerator::class);
        $recursiveStoragePids = $pidList;
        $storagePids = GeneralUtility::intExplode(',', $pidList);
        foreach ($storagePids as $startPid) {
            $pids = $queryGenerator->getTreeList($startPid, $recursive, 0, 1);
            if (strlen($pids) > 0) {
                $recursiveStoragePids .= ',' . $pids;
            }
        }
        return $recursiveStoragePids;
    }
}