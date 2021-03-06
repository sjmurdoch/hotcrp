<?php
// updateschema.php -- HotCRP function for updating old schemata
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

function update_schema_create_review_form($Conf) {
    global $reviewScoreNames;
    if (!($result = $Conf->ql("select * from ReviewFormField where fieldName!='outcome'")))
        return false;
    $rfj = (object) array();
    while (($row = edb_orow($result))) {
        $field = (object) array();
        $field->name = $row->shortName;
        if (trim($row->description) != "")
            $field->description = trim($row->description);
        if ($row->sortOrder >= 0)
            $field->position = $row->sortOrder + 1;
        if ($row->rows > 3)
            $field->display_space = (int) $row->rows;
        $field->view_score = (int) $row->authorView;
        if (in_array($row->fieldName, $reviewScoreNames)) {
            $field->options = array();
            if ((int) $row->levelChar > 1)
                $field->option_letter = (int) $row->levelChar;
        }
        $fname = $row->fieldName;
        $rfj->$fname = $field;
    }

    if (!($result = $Conf->ql("select * from ReviewFormOptions where fieldName!='outcome' order by level asc")))
        return false;
    while (($row = edb_orow($result))) {
        $fname = $row->fieldName;
        if (isset($rfj->$fname) && isset($rfj->$fname->options))
            $rfj->$fname->options[$row->level - 1] = $row->description;
    }

    return $Conf->save_setting("review_form", 1, $rfj);
}

function update_schema_create_options($Conf) {
    global $reviewScoreNames;
    if (!($result = $Conf->ql("select * from OptionType")))
        return false;
    $opsj = (object) array();
    $byabbr = array();
    while (($row = edb_orow($result))) {
        // backward compatibility with old schema versions
        if (!isset($row->optionValues))
            $row->optionValues = "";
        if (!isset($row->type) && $row->optionValues == "\x7Fi")
            $row->type = 2;
        else if (!isset($row->type))
            $row->type = ($row->optionValues ? 1 : 0);

        $opj = (object) array();
        $opj->id = $row->optionId;
        $opj->name = $row->optionName;

        $abbr = PaperOption::abbreviate($opj->name, $opj->id);
        if (!@$byabbr[$abbr]) {
            $opj->abbr = $abbr;
            $byabbr[$abbr] = $opj;
        } else {
            $opj->abbr = "opt$opj->id";
            $byabbr[$abbr]->abbr = "opt" . $byabbr[$abbr]->id;
        }

        if (trim($row->description) != "")
            $opj->description = trim($row->description);

        if ($row->pcView == 2)
            $opj->view_type = "nonblind";
        else if ($row->pcView == 0)
            $opj->view_type = "admin";

        $opj->position = (int) $row->sortOrder;
        if ($row->displayType == 1)
            $opj->highlight = true;
        else if ($row->displayType == 2)
            $opj->near_submission = true;

        switch ($row->type) {
        case 0:
            $opj->type = "checkbox";
            break;
        case 1:
            $opj->type = "selector";
            $opj->selector = explode("\n", rtrim($row->optionValues));
            break;
        case 2:
            $opj->type = "numeric";
            break;
        case 3:
            $opj->type = "text";
            $opj->display_space = 1;
            break;
        case 4:
            $opj->type = "pdf";
            break;
        case 5:
            $opj->type = "slides";
            break;
        case 6:
            $opj->type = "video";
            break;
        case 7:
            $opj->type = "radio";
            $opj->selector = explode("\n", rtrim($row->optionValues));
            break;
        case 8:
            $opj->type = "text";
            $opj->display_space = 5;
            break;
        case 9:
            $opj->type = "attachments";
            break;
        case 100:
            $opj->type = "pdf";
            $opj->final = true;
            break;
        case 101:
            $opj->type = "slides";
            $opj->final = true;
            break;
        case 102:
            $opj->type = "video";
            $opj->final = true;
            break;
        }

        $oid = $opj->id;
        $opsj->$oid = $opj;
    }

    return $Conf->save_setting("options", 1, $opsj);
}

function update_schema_version($Conf, $n) {
    if ($Conf->ql("update Settings set value=$n where name='allowPaperOption'")) {
        $Conf->settings["allowPaperOption"] = $n;
        return true;
    } else
        return false;
}

function updateSchema($Conf) {
    global $Opt, $OK;
    // avoid error message abut timezone, set to $Opt
    // (which might be overridden by database values later)
    if (function_exists("date_default_timezone_set") && @$Opt["timezone"])
        date_default_timezone_set($Opt["timezone"]);

    error_log($Opt["dbName"] . ": updating schema from version " . $Conf->settings["allowPaperOption"]);

    if ($Conf->settings["allowPaperOption"] == 6
        && $Conf->ql("alter table ReviewRequest add `reason` text"))
        update_schema_version($Conf, 7);
    if ($Conf->settings["allowPaperOption"] == 7
        && $Conf->ql("alter table PaperReview add `textField7` mediumtext NOT NULL")
        && $Conf->ql("alter table PaperReview add `textField8` mediumtext NOT NULL")
        && $Conf->ql("insert into ReviewFormField set fieldName='textField7', shortName='Additional text field'")
        && $Conf->ql("insert into ReviewFormField set fieldName='textField8', shortName='Additional text field'"))
        update_schema_version($Conf, 8);
    if ($Conf->settings["allowPaperOption"] == 8
        && $Conf->ql("alter table ReviewFormField add `levelChar` tinyint(1) NOT NULL default '0'")
        && $Conf->ql("alter table PaperReviewArchive add `textField7` mediumtext NOT NULL")
        && $Conf->ql("alter table PaperReviewArchive add `textField8` mediumtext NOT NULL"))
        update_schema_version($Conf, 9);
    if ($Conf->settings["allowPaperOption"] == 9
        && $Conf->ql("alter table Paper add `sha1` varbinary(20) NOT NULL default ''"))
        update_schema_version($Conf, 10);
    if ($Conf->settings["allowPaperOption"] == 10
        && $Conf->ql("alter table PaperReview add `reviewRound` tinyint(1) NOT NULL default '0'")
        && $Conf->ql("alter table PaperReviewArchive add `reviewRound` tinyint(1) NOT NULL default '0'")
        && $Conf->ql("alter table PaperReview add key `reviewRound` (`reviewRound`)")
        && update_schema_version($Conf, 11)) {
        if (count($Conf->round_list()) > 1) {
            // update review rounds (XXX locking)
            $result = $Conf->ql("select paperId, tag from PaperTag where tag like '%~%'");
            $rrs = array();
            while (($row = edb_row($result))) {
                list($contact, $round) = explode("~", $row[1]);
                if (($round = array_search($round, $Conf->round_list()))) {
                    if (!isset($rrs[$round]))
                        $rrs[$round] = array();
                    $rrs[$round][] = "(contactId=$contact and paperId=$row[0])";
                }
            }
            foreach ($rrs as $round => $pairs) {
                $q = "update PaperReview set reviewRound=$round where " . join(" or ", $pairs);
                $Conf->ql($q);
            }
            $x = trim(preg_replace('/(\S+)\s*/', "tag like '%~\$1' or ", $Conf->setting_data("tag_rounds")));
            $Conf->ql("delete from PaperTag where " . substr($x, 0, strlen($x) - 3));
        }
    }
    if ($Conf->settings["allowPaperOption"] == 11
        && $Conf->ql("create table `ReviewRating` (
  `reviewId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `rating` tinyint(1) NOT NULL default '0',
  UNIQUE KEY `reviewContact` (`reviewId`,`contactId`),
  UNIQUE KEY `reviewContactRating` (`reviewId`,`contactId`,`rating`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8"))
        update_schema_version($Conf, 12);
    if ($Conf->settings["allowPaperOption"] == 12
        && $Conf->ql("alter table PaperReview add `reviewToken` int(11) NOT NULL default '0'"))
        update_schema_version($Conf, 13);
    if ($Conf->settings["allowPaperOption"] == 13
        && $Conf->ql("alter table OptionType add `optionValues` text NOT NULL default ''"))
        update_schema_version($Conf, 14);
    if ($Conf->settings["allowPaperOption"] == 14
        && $Conf->ql("insert into Settings (name, value) select 'rev_tokens', count(reviewId) from PaperReview where reviewToken!=0 on duplicate key update value=values(value)"))
        update_schema_version($Conf, 15);
    if ($Conf->settings["allowPaperOption"] == 15) {
        // It's OK if this fails!  Update 11 added reviewRound to
        // PaperReviewArchive (so old databases have the column), but I forgot
        // to upgrade schema.sql (so new databases lack the column).
        $Conf->ql("alter table PaperReviewArchive add `reviewRound` tinyint(1) NOT NULL default '0'");
        $OK = true;
        update_schema_version($Conf, 16);
    }
    if ($Conf->settings["allowPaperOption"] == 16
        && $Conf->ql("alter table PaperReview add `reviewEditVersion` int(1) NOT NULL default '0'"))
        update_schema_version($Conf, 17);
    if ($Conf->settings["allowPaperOption"] == 17
        && $Conf->ql("alter table PaperReviewPreference add key `paperId` (`paperId`)")
        && $Conf->ql("create table PaperRank (
  `paperId` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `rank` int(11) NOT NULL,
  UNIQUE KEY `contactPaper` (`contactId`,`paperId`),
  KEY `paperId` (`paperId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;"))
        update_schema_version($Conf, 18);
    if ($Conf->settings["allowPaperOption"] == 18
        && $Conf->ql("alter table PaperComment add `replyTo` int(11) NOT NULL"))
        update_schema_version($Conf, 19);
    if ($Conf->settings["allowPaperOption"] == 19
        && $Conf->ql("drop table PaperRank"))
        update_schema_version($Conf, 20);
    if ($Conf->settings["allowPaperOption"] == 20
        && $Conf->ql("alter table PaperComment add `timeNotified` int(11) NOT NULL default '0'"))
        update_schema_version($Conf, 21);
    if ($Conf->settings["allowPaperOption"] == 21
        && $Conf->ql("update PaperConflict set conflictType=8 where conflictType=3"))
        update_schema_version($Conf, 22);
    if ($Conf->settings["allowPaperOption"] == 22
        && $Conf->ql("insert into ChairAssistant (contactId) select contactId from Chair on duplicate key update ChairAssistant.contactId=ChairAssistant.contactId")
        && $Conf->ql("update ContactInfo set roles=roles+2 where roles=5"))
        update_schema_version($Conf, 23);
    if ($Conf->settings["allowPaperOption"] == 23)
        update_schema_version($Conf, 24);
    if ($Conf->settings["allowPaperOption"] == 24
        && $Conf->ql("alter table ContactInfo add `preferredEmail` varchar(120)"))
        update_schema_version($Conf, 25);
    if ($Conf->settings["allowPaperOption"] == 25) {
        if ($Conf->settings["final_done"] > 0
            && !isset($Conf->settings["final_soft"])
            && $Conf->ql("insert into Settings (name, value) values ('final_soft', " . $Conf->settings["final_done"] . ") on duplicate key update value=values(value)"))
            $Conf->settings["final_soft"] = $Conf->settings["final_done"];
        update_schema_version($Conf, 26);
    }
    if ($Conf->settings["allowPaperOption"] == 26
        && $Conf->ql("alter table PaperOption add `data` text")
        && $Conf->ql("alter table OptionType add `type` tinyint(1) NOT NULL default '0'")
        && $Conf->ql("update OptionType set type=2 where optionValues='\x7Fi'")
        && $Conf->ql("update OptionType set type=1 where type=0 and optionValues!=''"))
        update_schema_version($Conf, 27);
    if ($Conf->settings["allowPaperOption"] == 27
        && $Conf->ql("alter table PaperStorage add `sha1` varbinary(20) NOT NULL default ''")
        && $Conf->ql("alter table PaperStorage add `documentType` int(3) NOT NULL default '0'")
        && $Conf->ql("update PaperStorage s, Paper p set s.sha1=p.sha1 where s.paperStorageId=p.paperStorageId and p.finalPaperStorageId=0 and s.paperStorageId>0")
        && $Conf->ql("update PaperStorage s, Paper p set s.sha1=p.sha1, s.documentType=" . DTYPE_FINAL . " where s.paperStorageId=p.finalPaperStorageId and s.paperStorageId>0"))
        update_schema_version($Conf, 28);
    if ($Conf->settings["allowPaperOption"] == 28
        && $Conf->ql("alter table OptionType add `sortOrder` tinyint(1) NOT NULL default '0'"))
        update_schema_version($Conf, 29);
    if ($Conf->settings["allowPaperOption"] == 29
        && $Conf->ql("delete from Settings where name='pldisplay_default'"))
        update_schema_version($Conf, 30);
    if ($Conf->settings["allowPaperOption"] == 30
        && $Conf->ql("DROP TABLE IF EXISTS `Formula`")
        && $Conf->ql("CREATE TABLE `Formula` (
  `formulaId` int(11) NOT NULL auto_increment,
  `name` varchar(200) NOT NULL,
  `heading` varchar(200) NOT NULL default '',
  `headingTitle` text NOT NULL default '',
  `expression` text NOT NULL,
  `authorView` tinyint(1) NOT NULL default '1',
  PRIMARY KEY  (`formulaId`),
  UNIQUE KEY `formulaId` (`formulaId`),
  UNIQUE KEY `name` (`name`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8"))
        update_schema_version($Conf, 31);
    if ($Conf->settings["allowPaperOption"] == 31
        && $Conf->ql("alter table Formula add `createdBy` int(11) NOT NULL default '0'")
        && $Conf->ql("alter table Formula add `timeModified` int(11) NOT NULL default '0'")
        && $Conf->ql("alter table Formula drop index `name`"))
        update_schema_version($Conf, 32);
    if ($Conf->settings["allowPaperOption"] == 32
        && $Conf->ql("alter table PaperComment add key `timeModified` (`timeModified`)"))
        update_schema_version($Conf, 33);
    if ($Conf->settings["allowPaperOption"] == 33
        && $Conf->ql("alter table PaperComment add `paperStorageId` int(11) NOT NULL default '0'"))
        update_schema_version($Conf, 34);
    if ($Conf->settings["allowPaperOption"] == 34
        && $Conf->ql("alter table ContactInfo add `contactTags` text"))
        update_schema_version($Conf, 35);
    if ($Conf->settings["allowPaperOption"] == 35
        && $Conf->ql("alter table ContactInfo modify `defaultWatch` int(11) NOT NULL default '2'")
        && $Conf->ql("alter table PaperWatch modify `watch` int(11) NOT NULL default '0'"))
        update_schema_version($Conf, 36);
    if ($Conf->settings["allowPaperOption"] == 36
        && $Conf->ql("alter table PaperReview add `reviewNotified` int(1) default NULL")
        && $Conf->ql("alter table PaperReviewArchive add `reviewNotified` int(1) default NULL"))
        update_schema_version($Conf, 37);
    if ($Conf->settings["allowPaperOption"] == 37
        && $Conf->ql("alter table OptionType add `displayType` tinyint(1) NOT NULL default '0'"))
        update_schema_version($Conf, 38);
    if ($Conf->settings["allowPaperOption"] == 38
        && $Conf->ql("update PaperComment set forReviewers=1 where forReviewers=-1"))
        update_schema_version($Conf, 39);
    if ($Conf->settings["allowPaperOption"] == 39
        && $Conf->ql("CREATE TABLE `MailLog` (
  `mailId` int(11) NOT NULL auto_increment,
  `recipients` varchar(200) NOT NULL,
  `paperIds` text,
  `cc` text,
  `replyto` text,
  `subject` text,
  `emailBody` text,
  PRIMARY KEY  (`mailId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8"))
        update_schema_version($Conf, 40);
    if ($Conf->settings["allowPaperOption"] == 40
        && $Conf->ql("alter table Paper add `capVersion` int(1) NOT NULL default '0'"))
        update_schema_version($Conf, 41);
    if ($Conf->settings["allowPaperOption"] == 41
        && $Conf->ql("alter table Paper modify `mimetype` varchar(80) NOT NULL default ''")
        && $Conf->ql("alter table PaperStorage modify `mimetype` varchar(80) NOT NULL default ''"))
        update_schema_version($Conf, 42);
    if ($Conf->settings["allowPaperOption"] == 42
        && $Conf->ql("alter table PaperComment add `ordinal` int(11) NOT NULL default '0'"))
        update_schema_version($Conf, 43);
    if ($Conf->settings["allowPaperOption"] == 42
        && ($result = $Conf->ql("describe PaperComment `ordinal`"))
        && ($o = edb_orow($result))
        && substr($o->Type, 0, 3) == "int"
        && (!$o->Null || $o->Null == "NO")
        && (!$o->Default || $o->Default == "0"))
        update_schema_version($Conf, 43);
    if ($Conf->settings["allowPaperOption"] == 43
        && $Conf->ql("alter table Paper add `withdrawReason` text"))
        update_schema_version($Conf, 44);
    if ($Conf->settings["allowPaperOption"] == 44
        && $Conf->ql("alter table PaperStorage add `filename` varchar(255)"))
        update_schema_version($Conf, 45);
    if ($Conf->settings["allowPaperOption"] == 45
        && $Conf->ql("alter table PaperReview add `timeRequested` int(11) NOT NULL DEFAULT '0'")
        && $Conf->ql("alter table PaperReview add `timeRequestNotified` int(11) NOT NULL DEFAULT '0'")
        && $Conf->ql("alter table PaperReviewArchive add `timeRequested` int(11) NOT NULL DEFAULT '0'")
        && $Conf->ql("alter table PaperReviewArchive add `timeRequestNotified` int(11) NOT NULL DEFAULT '0'")
        && $Conf->ql("alter table PaperReview drop column `requestedOn`")
        && $Conf->ql("alter table PaperReviewArchive drop column `requestedOn`"))
        update_schema_version($Conf, 46);
    if ($Conf->settings["allowPaperOption"] == 46
        && $Conf->ql("alter table ContactInfo add `disabled` tinyint(1) NOT NULL DEFAULT '0'"))
        update_schema_version($Conf, 47);
    if ($Conf->settings["allowPaperOption"] == 47
        && $Conf->ql("delete from ti using TopicInterest ti left join TopicArea ta using (topicId) where ta.topicId is null"))
        update_schema_version($Conf, 48);
    if ($Conf->settings["allowPaperOption"] == 48
        && $Conf->ql("alter table PaperReview add `reviewAuthorNotified` int(11) NOT NULL DEFAULT '0'")
        && $Conf->ql("alter table PaperReviewArchive add `reviewAuthorNotified` int(11) NOT NULL DEFAULT '0'")
        && $Conf->ql("alter table PaperReviewArchive add `reviewToken` int(11) NOT NULL DEFAULT '0'"))
        update_schema_version($Conf, 49);
    if ($Conf->settings["allowPaperOption"] == 49
        && $Conf->ql("alter table PaperOption drop index `paperOption`")
        && $Conf->ql("alter table PaperOption add index `paperOption` (`paperId`,`optionId`,`value`)"))
        update_schema_version($Conf, 50);
    if ($Conf->settings["allowPaperOption"] == 50
        && $Conf->ql("alter table Paper add `managerContactId` int(11) NOT NULL DEFAULT '0'"))
        update_schema_version($Conf, 51);
    if ($Conf->settings["allowPaperOption"] == 51
        && $Conf->ql("alter table Paper drop column `numComments`")
        && $Conf->ql("alter table Paper drop column `numAuthorComments`"))
        update_schema_version($Conf, 52);
    // Due to a bug in the schema updater, some databases might have
    // sversion>=53 but no `PaperComment.commentType` column. Fix them.
    if (($Conf->settings["allowPaperOption"] == 52
         || ($Conf->settings["allowPaperOption"] >= 53
             && ($result = $Conf->ql("show columns from PaperComment like 'commentType'"))
             && edb_nrows($result) == 0))
        && $Conf->ql("lock tables PaperComment write, Settings write")
        && $Conf->ql("alter table PaperComment add `commentType` int(11) NOT NULL DEFAULT '0'")) {
        if (($new_sversion = $Conf->settings["allowPaperOption"]) < 53)
            $new_sversion = 53;
        $result = $Conf->ql("show columns from PaperComment like 'forAuthors'");
        if ((!$result
             || edb_nrows($result) == 0
             || ($Conf->ql("update PaperComment set commentType=" . (COMMENTTYPE_AUTHOR | COMMENTTYPE_RESPONSE) . " where forAuthors=2")
                 && $Conf->ql("update PaperComment set commentType=commentType|" . COMMENTTYPE_DRAFT . " where forAuthors=2 and forReviewers=0")
                 && $Conf->ql("update PaperComment set commentType=" . COMMENTTYPE_ADMINONLY . " where forAuthors=0 and forReviewers=2")
                 && $Conf->ql("update PaperComment set commentType=" . COMMENTTYPE_PCONLY . " where forAuthors=0 and forReviewers=0")
                 && $Conf->ql("update PaperComment set commentType=" . COMMENTTYPE_REVIEWER . " where forAuthors=0 and forReviewers=1")
                 && $Conf->ql("update PaperComment set commentType=" . COMMENTTYPE_AUTHOR . " where forAuthors!=0 and forAuthors!=2")
                 && $Conf->ql("update PaperComment set commentType=commentType|" . COMMENTTYPE_BLIND . " where blind=1")))
            && $Conf->ql("update Settings set value=$new_sversion where name='allowPaperOption'"))
            $Conf->settings["allowPaperOption"] = $new_sversion;
    }
    if ($Conf->settings["allowPaperOption"] < 53)
        $Conf->ql("alter table PaperComment drop column `commentType`");
    $Conf->ql("unlock tables");
    if ($Conf->settings["allowPaperOption"] == 53
        && $Conf->ql("alter table PaperComment drop column `forReviewers`")
        && $Conf->ql("alter table PaperComment drop column `forAuthors`")
        && $Conf->ql("alter table PaperComment drop column `blind`"))
        update_schema_version($Conf, 54);
    if ($Conf->settings["allowPaperOption"] == 54
        && $Conf->ql("alter table PaperStorage add `infoJson` varchar(255) DEFAULT NULL"))
        update_schema_version($Conf, 55);
    if ($Conf->settings["allowPaperOption"] == 55
        && $Conf->ql("alter table ContactInfo modify `password` varbinary(2048) NOT NULL"))
        update_schema_version($Conf, 56);
    if ($Conf->settings["allowPaperOption"] == 56
        && $Conf->ql("alter table Settings modify `data` blob"))
        update_schema_version($Conf, 57);
    if ($Conf->settings["allowPaperOption"] == 57
        && $Conf->ql("DROP TABLE IF EXISTS `Capability`")
        && $Conf->ql("CREATE TABLE `Capability` (
  `capabilityId` int(11) NOT NULL AUTO_INCREMENT,
  `capabilityType` int(11) NOT NULL,
  `contactId` int(11) NOT NULL,
  `paperId` int(11) NOT NULL,
  `timeExpires` int(11) NOT NULL,
  `salt` varbinary(255) NOT NULL,
  `data` blob,
  PRIMARY KEY (`capabilityId`),
  UNIQUE KEY `capabilityId` (`capabilityId`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8")
        && $Conf->ql("DROP TABLE IF EXISTS `CapabilityMap`")
        && $Conf->ql("CREATE TABLE `CapabilityMap` (
  `capabilityValue` varbinary(255) NOT NULL,
  `capabilityId` int(11) NOT NULL,
  `timeExpires` int(11) NOT NULL,
  PRIMARY KEY (`capabilityValue`),
  UNIQUE KEY `capabilityValue` (`capabilityValue`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8"))
        update_schema_version($Conf, 58);
    if ($Conf->settings["allowPaperOption"] == 58
        && $Conf->ql("alter table PaperReview modify `paperSummary` mediumtext DEFAULT NULL")
        && $Conf->ql("alter table PaperReview modify `commentsToAuthor` mediumtext DEFAULT NULL")
        && $Conf->ql("alter table PaperReview modify `commentsToPC` mediumtext DEFAULT NULL")
        && $Conf->ql("alter table PaperReview modify `commentsToAddress` mediumtext DEFAULT NULL")
        && $Conf->ql("alter table PaperReview modify `weaknessOfPaper` mediumtext DEFAULT NULL")
        && $Conf->ql("alter table PaperReview modify `strengthOfPaper` mediumtext DEFAULT NULL")
        && $Conf->ql("alter table PaperReview modify `textField7` mediumtext DEFAULT NULL")
        && $Conf->ql("alter table PaperReview modify `textField8` mediumtext DEFAULT NULL")
        && $Conf->ql("alter table PaperReviewArchive modify `paperSummary` mediumtext DEFAULT NULL")
        && $Conf->ql("alter table PaperReviewArchive modify `commentsToAuthor` mediumtext DEFAULT NULL")
        && $Conf->ql("alter table PaperReviewArchive modify `commentsToPC` mediumtext DEFAULT NULL")
        && $Conf->ql("alter table PaperReviewArchive modify `commentsToAddress` mediumtext DEFAULT NULL")
        && $Conf->ql("alter table PaperReviewArchive modify `weaknessOfPaper` mediumtext DEFAULT NULL")
        && $Conf->ql("alter table PaperReviewArchive modify `strengthOfPaper` mediumtext DEFAULT NULL")
        && $Conf->ql("alter table PaperReviewArchive modify `textField7` mediumtext DEFAULT NULL")
        && $Conf->ql("alter table PaperReviewArchive modify `textField8` mediumtext DEFAULT NULL"))
        update_schema_version($Conf, 59);
    if ($Conf->settings["allowPaperOption"] == 59
        && $Conf->ql("alter table ActionLog modify `action` varbinary(4096) NOT NULL")
        && $Conf->ql("alter table ContactInfo modify `note` varbinary(4096) DEFAULT NULL")
        && $Conf->ql("alter table ContactInfo modify `collaborators` varbinary(32767) DEFAULT NULL")
        && $Conf->ql("alter table ContactInfo modify `contactTags` varbinary(4096) DEFAULT NULL")
        && $Conf->ql("alter table Formula modify `headingTitle` varbinary(4096) NOT NULL")
        && $Conf->ql("alter table Formula modify `expression` varbinary(4096) NOT NULL")
        && $Conf->ql("alter table OptionType modify `description` varbinary(8192) DEFAULT NULL")
        && $Conf->ql("alter table OptionType modify `optionValues` varbinary(8192) NOT NULL")
        && $Conf->ql("alter table PaperReviewRefused modify `reason` varbinary(32767) DEFAULT NULL")
        && $Conf->ql("alter table ReviewFormField modify `description` varbinary(32767) DEFAULT NULL")
        && $Conf->ql("alter table ReviewFormOptions modify `description` varbinary(32767) DEFAULT NULL")
        && $Conf->ql("alter table ReviewRequest modify `reason` varbinary(32767) DEFAULT NULL")
        && $Conf->ql("alter table Settings modify `data` varbinary(32767) DEFAULT NULL")
        && $Conf->ql("alter table ContactAddress modify `addressLine1` varchar(2048) NOT NULL")
        && $Conf->ql("alter table ContactAddress modify `addressLine2` varchar(2048) NOT NULL")
        && $Conf->ql("alter table ContactAddress modify `city` varchar(2048) NOT NULL")
        && $Conf->ql("alter table ContactAddress modify `state` varchar(2048) NOT NULL")
        && $Conf->ql("alter table ContactAddress modify `zipCode` varchar(2048) NOT NULL")
        && $Conf->ql("alter table ContactAddress modify `country` varchar(2048) NOT NULL")
        && $Conf->ql("alter table PaperTopic modify `topicId` int(11) NOT NULL")
        && $Conf->ql("alter table PaperTopic modify `paperId` int(11) NOT NULL")
        && $Conf->ql("drop table if exists ChairTag"))
        update_schema_version($Conf, 60);
    if ($Conf->settings["allowPaperOption"] == 60
        && $Conf->ql("insert into Settings (name,value,data) select concat('msg.',substr(name,1,length(name)-3)), value, data from Settings where name='homemsg' or name='conflictdefmsg'")
        && $Conf->ql("update Settings set value=61 where name='allowPaperOption'")) {
        foreach (array("conflictdef", "home") as $k)
            if (isset($Conf->settingTexts["${k}msg"]))
                $Conf->settingTexts["msg.$k"] = $Conf->settingTexts["${k}msg"];
        $Conf->settings["allowPaperOption"] = 61;
    }
    if ($Conf->settings["allowPaperOption"] == 61
        && $Conf->ql("alter table Capability modify `data` varbinary(4096) DEFAULT NULL"))
        update_schema_version($Conf, 62);
    if (!isset($Conf->settings["outcome_map"])) {
        $ojson = array();
        $result = $Conf->ql("select * from ReviewFormOptions where fieldName='outcome'");
        while (($row = edb_orow($result)))
            $ojson[$row->level] = $row->description;
        $Conf->save_setting("outcome_map", 1, $ojson);
    }
    if ($Conf->settings["allowPaperOption"] == 62
        && isset($Conf->settings["outcome_map"]))
        update_schema_version($Conf, 63);
    if (!isset($Conf->settings["review_form"])
        && $Conf->settings["allowPaperOption"] < 65)
        update_schema_create_review_form($Conf);
    if ($Conf->settings["allowPaperOption"] == 63
        && isset($Conf->settings["review_form"]))
        update_schema_version($Conf, 64);
    if ($Conf->settings["allowPaperOption"] == 64
        && $Conf->ql("drop table if exists `ReviewFormField`")
        && $Conf->ql("drop table if exists `ReviewFormOptions`"))
        update_schema_version($Conf, 65);
    if (!isset($Conf->settings["options"])
        && $Conf->settings["allowPaperOption"] < 67)
        update_schema_create_options($Conf);
    if ($Conf->settings["allowPaperOption"] == 65
        && isset($Conf->settings["options"]))
        update_schema_version($Conf, 66);
    if ($Conf->settings["allowPaperOption"] == 66
        && $Conf->ql("drop table if exists `OptionType`"))
        update_schema_version($Conf, 67);
    if ($Conf->settings["allowPaperOption"] == 67
        && $Conf->ql("alter table PaperComment modify `comment` varbinary(32767) DEFAULT NULL")
        && $Conf->ql("alter table PaperComment add `commentTags` varbinary(1024) DEFAULT NULL"))
        update_schema_version($Conf, 68);
    if ($Conf->settings["allowPaperOption"] == 68
        && $Conf->ql("alter table PaperReviewPreference add `expertise` int(4) DEFAULT NULL"))
        update_schema_version($Conf, 69);
    if ($Conf->settings["allowPaperOption"] == 69
        && $Conf->ql("alter table Paper drop column `pcPaper`"))
        update_schema_version($Conf, 70);
    if ($Conf->settings["allowPaperOption"] == 70
        && $Conf->ql("alter table ContactInfo modify `voicePhoneNumber` varbinary(256) DEFAULT NULL")
        && $Conf->ql("alter table ContactInfo modify `faxPhoneNumber` varbinary(256) DEFAULT NULL")
        && $Conf->ql("alter table ContactInfo modify `collaborators` varbinary(8192) DEFAULT NULL")
        && $Conf->ql("alter table ContactInfo drop column `note`")
        && $Conf->ql("alter table ContactInfo add `data` varbinary(32767) DEFAULT NULL"))
        update_schema_version($Conf, 71);
    if ($Conf->settings["allowPaperOption"] == 71
        && $Conf->ql("alter table Settings modify `name` varbinary(256) DEFAULT NULL")
        && $Conf->ql("update Settings set name=rtrim(name)"))
        update_schema_version($Conf, 72);
    if ($Conf->settings["allowPaperOption"] == 72
        && $Conf->ql("update TopicInterest set interest=-2 where interest=0")
        && $Conf->ql("update TopicInterest set interest=4 where interest=2")
        && $Conf->ql("delete from TopicInterest where interest=1"))
        update_schema_version($Conf, 73);
    if ($Conf->settings["allowPaperOption"] == 73
        && $Conf->ql("alter table PaperStorage add `size` bigint(11) DEFAULT NULL")
        && $Conf->ql("update PaperStorage set `size`=length(paper) where paper is not null"))
        update_schema_version($Conf, 74);
    if ($Conf->settings["allowPaperOption"] == 74
        && $Conf->ql("alter table ContactInfo drop column `visits`"))
        update_schema_version($Conf, 75);
    if ($Conf->settings["allowPaperOption"] == 75) {
        foreach (array("capability_gc", "s3_scope", "s3_signing_key") as $k)
            if (isset($Conf->settings[$k])) {
                $Conf->save_setting("__" . $k, $Conf->settings[$k], @$Conf->settingTexts[$k]);
                $Conf->save_setting($k, null);
            }
        $Conf->save_setting("allowPaperOption", 76);
    }
    if ($Conf->settings["allowPaperOption"] == 76
        && $Conf->ql("update PaperReviewPreference set expertise=-expertise"))
        update_schema_version($Conf, 77);
    if ($Conf->settings["allowPaperOption"] == 77
        && $Conf->ql("alter table MailLog add `q` varchar(4096)"))
        update_schema_version($Conf, 78);
    if ($Conf->settings["allowPaperOption"] == 78
        && $Conf->ql("alter table MailLog add `t` varchar(200)"))
        update_schema_version($Conf, 79);
    if ($Conf->settings["allowPaperOption"] == 79
        && $Conf->ql("alter table ContactInfo add `passwordTime` int(11) NOT NULL DEFAULT '0'"))
        update_schema_version($Conf, 80);
    if ($Conf->settings["allowPaperOption"] == 80
        && $Conf->ql("alter table PaperReview modify `reviewRound` int(11) NOT NULL DEFAULT '0'")
        && $Conf->ql("alter table PaperReviewArchive modify `reviewRound` int(11) NOT NULL DEFAULT '0'"))
        update_schema_version($Conf, 81);
    if ($Conf->settings["allowPaperOption"] == 81
        && $Conf->ql("alter table PaperStorage add `filterType` int(3) DEFAULT NULL")
        && $Conf->ql("alter table PaperStorage add `originalStorageId` int(11) DEFAULT NULL"))
        update_schema_version($Conf, 82);
}
