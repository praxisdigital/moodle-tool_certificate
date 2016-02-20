<?php
// This file is part of the customcert module for Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Provides functionality needed by certificates.
 *
 * @package    mod_customcert
 * @copyright  2016 Mark Nelson <markn@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_customcert;

defined('MOODLE_INTERNAL') || die();

/**
 * Class element
 *
 * All customercert element plugins are based on this class.
 */
class certificate {

    /**
     * @var string the print protection variable
     */
    const PROTECTION_PRINT = 'print';

    /**
     * @var string the modify protection variable
     */
    const PROTECTION_MODIFY = 'modify';

    /**
     * @var string the copy protection variable
     */
    const PROTECTION_COPY = 'copy';

    /**
     * @var int the number of issues that will be displayed on each page in the report
     *      If you want to display all customcerts on a page set this to 0.
     */
    const CUSTOMCERT_PER_PAGE = '20';

    /**
     * @var int the max number of issues to display
     */
    const CUSTOMCERT_MAX_PER_PAGE = '300';

    /**
     * Handles setting the protection field for the customcert
     *
     * @param \stdClass $data
     * @return string the value to insert into the protection field
     */
    public static function set_protection($data) {
        $protection = array();

        if (!empty($data->protection_print)) {
            $protection[] = self::PROTECTION_PRINT;
        }
        if (!empty($data->protection_modify)) {
            $protection[] = self::PROTECTION_MODIFY;
        }
        if (!empty($data->protection_copy)) {
            $protection[] = self::PROTECTION_COPY;
        }

        // Return the protection string.
        return implode(', ', $protection);
    }

    /**
     * Handles uploading an image for the customcert module.
     *
     * @param int $draftitemid the draft area containing the files
     * @param int $contextid the context we are storing this image in
     */
    public static function upload_imagefiles($draftitemid, $contextid) {
        // Save the file if it exists that is currently in the draft area.
        file_save_draft_area_files($draftitemid, $contextid, 'mod_customcert', 'image', 0);
    }

    /**
     * Return the list of possible fonts to use.
     */
    public static function get_fonts() {
        global $CFG;

        // Array to store the available fonts.
        $options = array();

        // Location of fonts in Moodle.
        $fontdir = "$CFG->dirroot/lib/tcpdf/fonts";
        // Check that the directory exists.
        if (file_exists($fontdir)) {
            // Get directory contents.
            $fonts = new \DirectoryIterator($fontdir);
            // Loop through the font folder.
            foreach ($fonts as $font) {
                // If it is not a file, or either '.' or '..', or
                // the extension is not php, or we can not open file,
                // skip it.
                if (!$font->isFile() || $font->isDot() || ($font->getExtension() != 'php')) {
                    continue;
                }
                // Set the name of the font to null, the include next should then set this
                // value, if it is not set then the file does not include the necessary data.
                $name = null;
                // Some files include a display name, the include next should then set this
                // value if it is present, if not then $name is used to create the display name.
                $displayname = null;
                // Some of the TCPDF files include files that are not present, so we have to
                // suppress warnings, this is the TCPDF libraries fault, grrr.
                @include("$fontdir/$font");
                // If no $name variable in file, skip it.
                if (is_null($name)) {
                    continue;
                }
                // Remove the extension of the ".php" file that contains the font information.
                $filename = basename($font, ".php");
                // Check if there is no display name to use.
                if (is_null($displayname)) {
                    // Format the font name, so "FontName-Style" becomes "Font Name - Style".
                    $displayname = preg_replace("/([a-z])([A-Z])/", "$1 $2", $name);
                    $displayname = preg_replace("/([a-zA-Z])-([a-zA-Z])/", "$1 - $2", $displayname);
                }
                $options[$filename] = $displayname;
            }
            ksort($options);
        }

        return $options;
    }

    /**
     * Return the list of possible font sizes to use.
     */
    public static function get_font_sizes() {
        // Array to store the sizes.
        $sizes = array();

        for ($i = 1; $i <= 60; $i++) {
            $sizes[$i] = $i;
        }

        return $sizes;
    }

    /**
     * Get the time the user has spent in the course.
     *
     * @param int $courseid
     * @return int the total time spent in seconds
     */
    public static function get_course_time($courseid) {
        global $CFG, $DB, $USER;

        $logmanager = get_log_manager();
        $readers = $logmanager->get_readers();
        $enabledreaders = get_config('tool_log', 'enabled_stores');
        $enabledreaders = explode(',', $enabledreaders);

        // Go through all the readers until we find one that we can use.
        foreach ($enabledreaders as $enabledreader) {
            $reader = $readers[$enabledreader];
            if ($reader instanceof \logstore_legacy\log\store) {
                $logtable = 'log';
                $coursefield = 'course';
                $timefield = 'time';
                break;
            } else if ($reader instanceof \core\log\sql_internal_reader) {
                $logtable = $reader->get_internal_log_table_name();
                $coursefield = 'courseid';
                $timefield = 'timecreated';
                break;
            }
        }

        // If we didn't find a reader then return 0.
        if (!isset($logtable)) {
            return 0;
        }

        $sql = "SELECT id, $timefield
                  FROM {{$logtable}}
                 WHERE userid = :userid
                   AND $coursefield = :courseid
              ORDER BY $timefield ASC";
        $params = array('userid' => $USER->id, 'courseid' => $courseid);
        $totaltime = 0;
        if ($logs = $DB->get_recordset_sql($sql, $params)) {
            foreach ($logs as $log) {
                if (!isset($login)) {
                    // For the first time $login is not set so the first log is also the first login
                    $login = $log->$timefield;
                    $lasthit = $log->$timefield;
                    $totaltime = 0;
                }
                $delay = $log->$timefield - $lasthit;
                if ($delay > ($CFG->sessiontimeout * 60)) {
                    // The difference between the last log and the current log is more than
                    // the timeout Register session value so that we have found a session!
                    $login = $log->$timefield;
                } else {
                    $totaltime += $delay;
                }
                // Now the actual log became the previous log for the next cycle
                $lasthit = $log->$timefield;
            }

            return $totaltime;
        }

        return 0;
    }

    /**
     * Returns a list of issued customcerts.
     *
     * @param int $customcertid
     * @param bool $groupmode are we in group mode
     * @param \stdClass $cm the course module
     * @param int $page offset
     * @param int $perpage total per page
     * @return \stdClass the users
     */
    public static function get_issues($customcertid, $groupmode, $cm, $page, $perpage) {
        global $DB;

        // Get the conditional SQL.
        list($conditionssql, $conditionsparams) = self::get_conditional_issues_sql($cm, $groupmode);

        // If it is empty then return an empty array.
        if (empty($conditionsparams)) {
            return array();
        }

        // Add the conditional SQL and the customcertid to form all used parameters.
        $allparams = $conditionsparams + array('customcertid' => $customcertid);

        // Return the issues.
        $sql = "SELECT u.*, ci.code, ci.timecreated
                  FROM {user} u
            INNER JOIN {customcert_issues} ci
                    ON u.id = ci.userid
                 WHERE u.deleted = 0
                   AND ci.customcertid = :customcertid
                       $conditionssql
              ORDER BY " . $DB->sql_fullname();
        return $DB->get_records_sql($sql, $allparams, $page * $perpage, $perpage);
    }

    /**
     * Returns the total number of issues for a given customcert.
     *
     * @param int $customcertid
     * @param \stdClass $cm the course module
     * @param bool $groupmode the group mode
     * @return int the number of issues
     */
    public static function get_number_of_issues($customcertid, $cm, $groupmode) {
        global $DB;

        // Get the conditional SQL.
        list($conditionssql, $conditionsparams) = self::get_conditional_issues_sql($cm, $groupmode);

        // If it is empty then return 0.
        if (empty($conditionsparams)) {
            return 0;
        }

        // Add the conditional SQL and the customcertid to form all used parameters.
        $allparams = $conditionsparams + array('customcertid' => $customcertid);

        // Return the number of issues.
        $sql = "SELECT COUNT(u.id) as count
                  FROM {user} u
            INNER JOIN {customcert_issues} ci
                    ON u.id = ci.userid
                 WHERE u.deleted = 0
                   AND ci.customcertid = :customcertid
                       $conditionssql";
        return $DB->count_records_sql($sql, $allparams);
    }

    /**
     * Returns an array of the conditional variables to use in the get_issues SQL query.
     *
     * @param \stdClass $cm the course module
     * @param bool $groupmode are we in group mode ?
     * @return array the conditional variables
     */
    public static function get_conditional_issues_sql($cm, $groupmode) {
        global $DB, $USER;

        // Get all users that can manage this customcert to exclude them from the report.
        $context = \context_module::instance($cm->id);
        $conditionssql = '';
        $conditionsparams = array();

        // Get all users that can manage this certificate to exclude them from the report.
        $certmanagers = array_keys(get_users_by_capability($context, 'mod/certificate:manage', 'u.id'));
        $certmanagers = array_merge($certmanagers, array_keys(get_admins()));
        list($sql, $params) = $DB->get_in_or_equal($certmanagers, SQL_PARAMS_NAMED, 'cert');
        $conditionssql .= "AND NOT u.id $sql \n";
        $conditionsparams += $params;

        if ($groupmode) {
            $canaccessallgroups = has_capability('moodle/site:accessallgroups', $context);
            $currentgroup = groups_get_activity_group($cm);

            // If we are viewing all participants and the user does not have access to all groups then return nothing.
            if (!$currentgroup && !$canaccessallgroups) {
                return array('', array());
            }

            if ($currentgroup) {
                if (!$canaccessallgroups) {
                    // Guest users do not belong to any groups.
                    if (isguestuser()) {
                        return array('', array());
                    }

                    // Check that the user belongs to the group we are viewing.
                    $usersgroups = groups_get_all_groups($cm->course, $USER->id, $cm->groupingid);
                    if ($usersgroups) {
                        if (!isset($usersgroups[$currentgroup])) {
                            return array('', array());
                        }
                    } else { // They belong to no group, so return an empty array.
                        return array('', array());
                    }
                }

                $groupusers = array_keys(groups_get_members($currentgroup, 'u.*'));
                if (empty($groupusers)) {
                    return array('', array());
                }

                list($sql, $params) = $DB->get_in_or_equal($groupusers, SQL_PARAMS_NAMED, 'grp');
                $conditionssql .= "AND u.id $sql ";
                $conditionsparams += $params;
            }
        }

        return array($conditionssql, $conditionsparams);
    }

    /**
     * Generates a 10-digit code of random letters and numbers.
     *
     * @return string
     */
    public static function generate_code() {
        global $DB;

        $uniquecodefound = false;
        $code = random_string(10);
        while (!$uniquecodefound) {
            if (!$DB->record_exists('customcert_issues', array('code' => $code))) {
                $uniquecodefound = true;
            } else {
                $code = random_string(10);
            }
        }

        return $code;
    }

    /**
     * Generate the report.
     *
     * @param \stdClass $customcert
     * @param \stdClass $users the list of users who have had a customcert issued
     * @param string $type
     */
    public static function generate_report_file($customcert, $users, $type) {
        global $CFG, $COURSE;

        if ($type == 'ods') {
            require_once($CFG->libdir . '/odslib.class.php');
            $workbook = new \MoodleODSWorkbook('-');
        } else if ($type == 'xls') {
            require_once($CFG->libdir . '/excellib.class.php');
            $workbook = new \MoodleExcelWorkbook('-');
        }

        $filename = clean_filename($COURSE->shortname . ' ' . rtrim($customcert->name, '.') . '.' . $type);

        // Send HTTP headers.
        $workbook->send($filename);

        // Creating the first worksheet.
        $myxls = $workbook->add_worksheet(get_string('report', 'customcert'));

        // Print names of all the fields.
        $myxls->write_string(0, 0, get_string('lastname'));
        $myxls->write_string(0, 1, get_string('firstname'));
        $myxls->write_string(0, 2, get_string('idnumber'));
        $myxls->write_string(0, 3, get_string('group'));
        $myxls->write_string(0, 4, get_string('receiveddate', 'customcert'));
        $myxls->write_string(0, 5, get_string('code', 'customcert'));

        // Generate the data for the body of the spreadsheet.
        $row = 1;
        if ($users) {
            foreach ($users as $user) {
                $myxls->write_string($row, 0, $user->lastname);
                $myxls->write_string($row, 1, $user->firstname);
                $studentid = (!empty($user->idnumber)) ? $user->idnumber : ' ';
                $myxls->write_string($row, 2, $studentid);
                $ug2 = '';
                if ($usergrps = groups_get_all_groups($COURSE->id, $user->id)) {
                    foreach ($usergrps as $ug) {
                        $ug2 = $ug2 . $ug->name;
                    }
                }
                $myxls->write_string($row, 3, $ug2);
                $myxls->write_string($row, 4, userdate($user->timecreated));
                $myxls->write_string($row, 5, $user->code);
                $row++;
            }
        }
        // Close the workbook.
        $workbook->close();
    }
}