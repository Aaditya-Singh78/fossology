<?php
/*
 SPDX-FileCopyrightText: © 2022 Rohit Pandey <rohit.pandey4900@gmail.com>

 SPDX-License-Identifier: GPL-2.0-only
*/

namespace Fossology\Lib\Dao;

use Fossology\Lib\Db\DbManager;

class SearchHelperDao
{
  /**
   * @var DbManager
   */
  private $dbManager;

  function __construct(DbManager $dbManager)
  {
    $this->dbManager = $dbManager;
  }

  /**
   * \brief Given a filename, return all uploadtree.
   * @param $Item     int - uploadtree_pk of tree to search, if empty, do global search
   * @param $Filename string - filename or pattern to search for, false if unused
   * @param $tag      string - tag (or tag pattern mytag%) to search for, false if unused
   * @param $Page     int - display page number
   * @param $Limit    int - size of the page
   * @param $SizeMin  int - Minimum file size, -1 if unused
   * @param $SizeMax  int - Maximum file size, -1 if unused
   * @param $searchtype "containers", "directory" or "allfiles"
   * @param $License string
   * @param $Copyright string
   * @param $uploadDao \Fossology\Lib\Dao\UploadDao
   * @param $groupID int
   * @return array of uploadtree recs and total uploadtree recs count. Each record
   *         contains uploadtree_pk, parent, upload_fk, pfile_fk, ufile_mode, and
   *         ufile_name
   */
  public function GetResults($Item, $Filename, $Upload, $tag, $Page, $Limit, $SizeMin, $SizeMax, $searchtype, $License, $Copyright, $uploadDao, $groupID)
  {
    $UploadtreeRecs = array();  // uploadtree record array to return
    $totalUploadtreeRecs = array();  // total uploadtree record array
    $totalUploadtreeRecsCount = 0; // total uploadtree records count to return
    $NeedTagfileTable = true;
    $NeedTaguploadtreeTable = true;

    if ($Item) {
      /* Find lft and rgt bounds for this $Uploadtree_pk  */
      $row = $uploadDao->getUploadEntry($Item);
      if (empty($row)) {
        $text = _("Invalid URL, nonexistant item");
        return "<h2>$text $Item</h2>";
      }
      $lft = $row["lft"];
      $rgt = $row["rgt"];
      $upload_pk = $row["upload_fk"];

      /* Check upload permission */
      if (!$uploadDao->isAccessible($upload_pk, $groupID)) {
        return array($UploadtreeRecs, $totalUploadtreeRecsCount);
      }
    }

    /* Start the result select stmt */
    $SQL = "SELECT DISTINCT uploadtree_pk, parent, upload_fk, uploadtree.pfile_fk, ufile_mode, ufile_name FROM uploadtree";

    if ($searchtype != "directory") {
      if (!empty($License)) {
        $SQL .= ", ( SELECT license_ref.rf_shortname, license_file.rf_fk, license_file.pfile_fk
                  FROM license_file JOIN license_ref ON license_file.rf_fk = license_ref.rf_pk) AS pfile_ref";
      }
      if (!empty($Copyright)) {
        $SQL .= ",copyright";
      }
    }

    /* Figure out the tag_pk's of interest */
    if (!empty($tag)) {
      $stmt = __METHOD__.$Filename;
      $sql = "select tag_pk from tag where tag ilike '" . pg_escape_string($tag) . "'";
      $tag_pk_array = $this->dbManager->getRows($sql, [], $stmt);
      if (empty($tag_pk_array)) {
        /* tag doesn't match anything, so no results are possible */
        return array($UploadtreeRecs, $totalUploadtreeRecsCount);
      }

      /* add the tables needed for the tag query */
      $sql = "select tag_file_pk from tag_file limit 1";
      $result = $this->dbManager->getRows($sql, [], $stmt);
      if (empty($result)) {
        /* tag_file didn't have data, don't add the tag_file table for tag query */
        $NeedTagfileTable = false;
      } else {
        $SQL .= ", tag_file";
      }

      /* add the tables needed for the tag query */
      $sql = "select tag_uploadtree_pk from tag_uploadtree limit 1";
      $result = $this->dbManager->getRows($sql, [], $stmt);
      if (empty($result)) {
        /* tag_uploadtree didn't have data, don't add the tag_uploadtree table for tag query */
        $NeedTaguploadtreeTable = false;
      } else {
        $SQL .= ", tag_uploadtree";
      }

      if (!$NeedTagfileTable && !$NeedTaguploadtreeTable) {
        $SQL .= ", tag_file, tag_uploadtree";
      }
    }

    /* do we need the pfile table? Yes, if any of these are a search critieria.  */
    if (!empty($SizeMin) or !empty($SizeMax)) {
      $SQL .= ", pfile where pfile_pk=uploadtree.pfile_fk ";
      $NeedAnd = true;
    } else {
      $SQL .= " where ";
      $NeedAnd = false;
    }

    /* add the tag conditions */
    if (!empty($tag)) {
      if ($NeedAnd) {
        $SQL .= " AND";
      }
      $SQL .= "(";
      $NeedOr = false;
      foreach ($tag_pk_array as $tagRec) {
        if ($NeedOr) {
          $SQL .= " OR";
        }
        $SQL .= "(";
        $tag_pk = $tagRec['tag_pk'];
        if ($NeedTagfileTable && $NeedTaguploadtreeTable) {
          $SQL .= "(uploadtree.pfile_fk=tag_file.pfile_fk and tag_file.tag_fk=$tag_pk) or (uploadtree_pk=tag_uploadtree.uploadtree_fk and tag_uploadtree.tag_fk=$tag_pk) ";
        } else if ($NeedTaguploadtreeTable) {
          $SQL .= "uploadtree_pk=tag_uploadtree.uploadtree_fk and tag_uploadtree.tag_fk=$tag_pk";
        } else if ($NeedTagfileTable) {
          $SQL .= "uploadtree.pfile_fk=tag_file.pfile_fk and tag_file.tag_fk=$tag_pk";
        } else {
          $SQL .= "(uploadtree.pfile_fk=tag_file.pfile_fk and tag_file.tag_fk=$tag_pk) or (uploadtree_pk=tag_uploadtree.uploadtree_fk and tag_uploadtree.tag_fk=$tag_pk) ";
        }
        $SQL .= ")";
        $NeedOr = 1;
      }
      $NeedAnd = 1;
      $SQL .= ")";
    }

    if ($Filename) {
      if ($NeedAnd) {
        $SQL .= " AND";
      }
      $SQL .= " ufile_name ilike '" . $Filename . "'";
      $NeedAnd = 1;
    }

    if ($Upload != 0) {
      if ($NeedAnd) {
        $SQL .= " AND";
      }
      $SQL .= " upload_fk = " . $Upload . "";
      $NeedAnd = 1;
    }

    if (!empty($SizeMin) && is_numeric($SizeMin)) {
      if ($NeedAnd) {
        $SQL .= " AND";
      }
      $SQL .= " pfile.pfile_size >= " . $SizeMin;
      $NeedAnd = 1;
    }

    if (!empty($SizeMax) && is_numeric($SizeMax)) {
      if ($NeedAnd) {
        $SQL .= " AND";
      }
      $SQL .= " pfile.pfile_size <= " . $SizeMax;
      $NeedAnd = 1;
    }

    if ($Item) {
      if ($NeedAnd) {
        $SQL .= " AND";
      }
      $SQL .= "  upload_fk = $upload_pk AND lft >= $lft AND rgt <= $rgt";
      $NeedAnd = 1;
    }

    /* search only containers */
    if ($searchtype == 'containers') {
      if ($NeedAnd) {
        $SQL .= " AND";
      }
      $SQL .= " ((ufile_mode & (1<<29))!=0) AND ((ufile_mode & (1<<28))=0)";
      $NeedAnd = 1;
    }
    $dir_ufile_mode = 536888320;
    if ($searchtype == 'directory') {
      if ($NeedAnd) {
        $SQL .= " AND";
      }
      $SQL .= " ((ufile_mode & (1<<29))!=0) AND ((ufile_mode & (1<<28))=0) AND (ufile_mode != $dir_ufile_mode) and pfile_fk != 0";
      $NeedAnd = 1;
    }

    /** license and copyright */
    if ($searchtype != "directory") {
      if (!empty($License)) {
        if ($NeedAnd) {
          $SQL .= " AND";
        }

        $SQL .= " uploadtree.pfile_fk=pfile_ref.pfile_fk and pfile_ref.rf_shortname ilike '" .
          pg_escape_string($License) . "'";
        $NeedAnd = 1;
      }
      if (!empty($Copyright)) {
        if ($NeedAnd) {
          $SQL .= " AND";
        }
        $SQL .= " uploadtree.pfile_fk=copyright.pfile_fk and copyright.content ilike '%" .
          pg_escape_string($Copyright) . "%'";
      }
    }

    $Offset = $Page * $Limit;
    $stmt = __METHOD__.$Filename;
    $SQL .= " ORDER BY ufile_name, uploadtree.pfile_fk";
    $rows = $this->dbManager->getRows($SQL, [], $stmt);
    if (!empty($rows)) {
      foreach ($rows as $row) {
        if (!$uploadDao->isAccessible($row['upload_fk'], $groupID)) {
          continue;
        }
        $totalUploadtreeRecs[] = $row;
      }
    }
    $UploadtreeRecs = array_slice($totalUploadtreeRecs, $Offset, $Limit);
    $totalUploadtreeRecsCount = sizeof($totalUploadtreeRecs);
    return array($UploadtreeRecs, $totalUploadtreeRecsCount);
  }
}
