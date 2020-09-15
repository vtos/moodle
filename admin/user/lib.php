<?php

require_once($CFG->dirroot.'/user/filters/lib.php');

if (!defined('MAX_BULK_USERS')) {
    define('MAX_BULK_USERS', 2000);
}

function add_selection_all($ufiltering) {
    global $SESSION, $DB, $CFG;

    list($sqlwhere, $params) = $ufiltering->get_sql_filter("id<>:exguest AND deleted <> 1", array('exguest'=>$CFG->siteguest));

    $rs = $DB->get_recordset_select('user', $sqlwhere, $params, 'fullname', 'id,'.$DB->sql_fullname().' AS fullname');
    foreach ($rs as $user) {
        if (!isset($SESSION->bulk_users[$user->id])) {
            $SESSION->bulk_users[$user->id] = $user->id;
        }
    }
    $rs->close();
}

function get_selection_data($ufiltering) {
    global $SESSION, $DB, $CFG;

    // Get the 'search' parameter for 'users_order_by_sql()' from the session's user filtering.
    $search = get_search_value_from_user_filtering();

    list($orderbysql, $orderbyparams) = users_order_by_sql('', $search);

    // get the SQL filter
    list($sqlwhere, $params) = $ufiltering->get_sql_filter("id<>:exguest AND deleted <> 1", array('exguest'=>$CFG->siteguest));

    $total  = $DB->count_records_select('user', "id<>:exguest AND deleted <> 1", array('exguest'=>$CFG->siteguest));
    $acount = $DB->count_records_select('user', $sqlwhere, $params);
    $scount = count($SESSION->bulk_users);

    $userlist = array('acount'=>$acount, 'scount'=>$scount, 'ausers'=>false, 'susers'=>false, 'total'=>$total);
    if ($orderbyparams) {
        $params += $orderbyparams;
    }
    $userlist['ausers'] = $DB->get_records_select_menu('user', $sqlwhere, $params, $orderbysql,
        'id,'.$DB->sql_fullname().' AS fullname', 0, MAX_BULK_USERS);

    if ($scount) {
        if ($scount < MAX_BULK_USERS) {
            $bulkusers = $SESSION->bulk_users;
        } else {
            $bulkusers = array_slice($SESSION->bulk_users, 0, MAX_BULK_USERS, true);
        }
        list($in, $inparams) = $DB->get_in_or_equal($bulkusers, SQL_PARAMS_NAMED);
        if ($orderbyparams) {
            $inparams += $orderbyparams;
        }
        $userlist['susers'] = $DB->get_records_select_menu('user', "id $in", $inparams, $orderbysql,
            'id,'.$DB->sql_fullname().' AS fullname');
    }

    return $userlist;
}

/**
 * Fetches the user filtering from $SESSION if applied
 * and uses it's fields to define the search string for users_order_by_sql
 *
 * @package core
 * @return string search string to use as a parameter in users_order_by_sql
 */
function get_search_value_from_user_filtering() {
    global $SESSION, $PAGE;

    $search = null;

    if ($SESSION->user_filtering) {
        // Available fields for the 'search' parameter.
        $fieldstocheck = array_merge(array('realname', 'firstname', 'lastname'), get_extra_user_fields($PAGE->context));

        // The assumption is that the 'search' parameter for 'users_order_by_sql()' does make sense
        // only for text fields like 'lastname', 'institution', 'city/town', etc.
        // and which are set in the 'showuseridentity' admin setting.
        if ($countrykey = array_search('country', $fieldstocheck)) {
            unset($fieldstocheck[$countrykey]);
        }

        // We have to use the 'magic numbers' here because the comparison operators for the text fields which are defined
        // in /user/filters/text.php in the 'getOperators()' method do not have any constants defined.
        // We're only interested in 0, 2, 3, 4 which correspond to the 'contains',
        // 'isequalto', 'startswith' and 'endswith' text operators.
        $searchoperators = array(0, 2, 3, 4);

        // Fetch the value of the first appropriate filtering field to set the 'search'.
        foreach ($fieldstocheck as $fieldtocheck) {
            if ($search) {
                break;
            }
            if (array_key_exists($fieldtocheck, $SESSION->user_filtering)) {
                foreach ($SESSION->user_filtering[$fieldtocheck] as $filteringfield) {
                    if (! in_array($filteringfield['operator'], $searchoperators)) {
                        continue;
                    }
                    $search = $filteringfield['value'];
                }
            }
        }
    }

    return $search;
}