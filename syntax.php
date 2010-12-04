<?php
/**
 * DokuWiki Plugin groupmgr (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Alex Forencich <alex@alexforencich.com>
 * 
 * Syntax:
 * ~~GROUPMGR|[groups to manage]|[allowed users and groups]~~
 * 
 * Examples:
 *   ~~GROUPMGR|posters|@moderators~~
 *   Members of group 'posters' can be managed by group 'moderators'
 * 
 *   ~~GROUPMGR|groupa, groupb|joe, @admin~~
 *   Members of groups 'groupa' and 'groupb' can be managed by user 'joe'
 *     members of the 'admin' group
 * 
 * Note: superuser groups can only be managed by super users,
 *       forbidden groups can be configured,
 *       and users cannot remove themselves from the group that lets them access
 *       the group manager (including admins)
 * 
 * Note: if require_conf_namespace config option is set, then plugin looks in
 *       conf_namespace:$ID for configuration.  Plugin will also check config
 *       namespace if a placeholder tag is used (~~GROUPMGR~~).  This is the
 *       default configuration for security reasons.
 * 
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_LF')) define('DOKU_LF', "\n");
if (!defined('DOKU_TAB')) define('DOKU_TAB', "\t");
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');

require_once DOKU_PLUGIN.'syntax.php';
    
function remove_item_by_value($val, $arr, $preserve = true) {
    if (empty($arr) || !is_array($arr)) { return false; }
    foreach(array_keys($arr,$val) as $key){ unset($arr[$key]); }
    return ($preserve) ? $arr : array_values($arr);
}

class syntax_plugin_groupmgr extends DokuWiki_Syntax_Plugin {
    /**
     * Plugin information
     */
    function getInfo(){
        return array(
            'author' => 'Alex Forencich',
            'email'  => 'alex@alexforencich.com',
            'date'   => '2010-11-28',
            'name'   => 'Group Manager Syntax plugin',
            'desc'   => 'Embeddable group manager',
            'url'    => 'http://www.alexforencich.com/'
        );
    }
    
    /**
     * Plugin type
     */
    function getType() {
        return 'substition';
    }
    
    /**
     * PType
     */
    function getPType() {
        return 'normal';
    }
    
    /**
     * Sort order
     */
    function getSort() {
        return 160;
    }
    
    /**
     * Register syntax handler
     */
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('~~GROUPMGR\|[^~]*?~~',$mode,'plugin_groupmgr');
        $this->Lexer->addSpecialPattern('~~GROUPMGR~~',$mode,'plugin_groupmgr');
    }
    
    /**
     * Handle match
     */
    function handle($match, $state, $pos, &$handler){
        $data = array(null, $state, $pos);
        
        if (strlen($match) == 12)
            return $data;
        
        // Strip away tag
        $match = substr($match, 11, -2);
        
        // split arguments
        $ar = explode("|", $match);
        
        $match = array();
        
        // reorganize into array
        foreach ($ar as $it) {
            $ar2 = explode(",", $it);
            foreach ($ar2 as &$it2)
                $it2 = trim($it2);
            $match[] = $ar2;
        }
        
        // pass to render method
        $data[0] = $match;
        
        return $data;
    }
    
    /**
     * Render it
     */
    function render($mode, &$renderer, $data) {
        global $auth;
        global $lang;
        global $INFO;
        global $conf;
        global $ID;
        
        // we are parsing a submitted comment...
        if (isset($_REQUEST['comment']))
            return false;
        
        // disable caching
        $renderer->info['cache'] = false;        
        
        $this->setupLocale();
        
        if (!method_exists($auth,"retrieveUsers")) return false;
        
        if ($mode == 'xhtml') {
            // need config namespace?
            if ($this->getConf('require_conf_namespace')) {
                // set it to null, it will be reloaded anyway
                $data[0] = null;
            }
            
            $conf_namespace = $this->getConf('conf_namespace');
            
            // empty tag?
            if (is_null($data[0]) || count($data[0]) == 0) {
                // load from conf namespace
                // build page name
                $conf_page = "";         
                if (substr($ID, 0, strlen($conf_namespace)) != $conf_namespace) {
                    $conf_page .= $conf_namespace;
                    if (substr($conf_page, -1) != ':') $conf_page .= ":";
                }
                $conf_page .= $ID;
                
                // get file name
                $fn = wikiFN($conf_page);
                
                if (!file_exists($fn))
                    return false;
                
                // read file
                $page = file_get_contents($fn);
                
                // find config tag
                $i = preg_match('/~~GROUPMGR\|[^~]*?~~/', $page, &$match);
                
                if ($i == 0)
                    return false;
                
                // parse config
                $match = substr($match[0], 11, -2);
                
                $ar = explode("|", $match);
                $match = array();
                
                // reorganize into array
                foreach ($ar as $it) {
                    $ar2 = explode(",", $it);
                    foreach ($ar2 as &$it2)
                        $it2 = trim($it2);
                    $match[] = $ar2;
                }
        
                // pass to render method
                $data[0] = $match;
            }
            
            // don't render if an argument hasn't been specified
            if (!isset($data[0][0]) || !isset($data[0][1]))
                return false;
            
            $grplst = $data[0][0];
            $authlst = $data[0][1];
            
            // parse forbidden groups
            $forbiddengrplst = array();
            $str = $this->getConf('forbidden_groups');
            if (isset($str)) {
                $arr = explode(",", $str);
                foreach ($arr as $val) {
                    $val = trim($val);
                    $forbiddengrplst[] = $val;
                }
            }
            
            // parse admin groups
            $admingrplst = array();
            if (isset($conf['superuser'])) {
                $arr = explode(",", $conf['superuser']);
                foreach ($arr as $val) {
                    $val = trim($val);
                    if ($val[0] == "@") {
                        $val = substr($val, 1);
                        $admingrplst[] = $val;
                    }
                }
            }
            
            // forbid admin groups if user is not a superuser
            if (!$INFO['isadmin']) {
                foreach ($admingrplst as $val) {
                    $forbiddengrplst[] = $val;
                }
            }
            
            // remove forbidden groups from group list
            foreach ($forbiddengrplst as $val) {
                $grplst = remove_item_by_value($val, $grplst, false);
            }
            
            // build array of user's credentials
            $check = array($_SERVER['REMOTE_USER']);
            if (is_array($INFO['userinfo'])) {            
                foreach ($INFO['userinfo']['grps'] as $val) {
                    $check[] = "@" . $val;
                }
            }
            
            // does user have permission?
            // Also, save authenticated group for later
            $authbygrp = "";
            $ok = 0;
            foreach ($authlst as $val) {
                if (in_array($val, $check)) {
                    $ok = 1;
                    if ($val[0] == "@") {
                        $authbygrp = substr($val, 1);
                    }
                }
            }
            
            // continue if user has explicit permission or is an admin
            if ($INFO['isadmin'] || $ok) {
                // authorized
                $status = 0;
                
                // nab user info
                $users = $auth->retrieveUsers(0, 0, array());
                
                // open form
                $renderer->doc .= "<form method=\"post\" action=\"" . htmlspecialchars($_SERVER['REQUEST_URI'])
                    . "\" name=\"groupmgr\" enctype=\"application/x-www-form-urlencoded\">";
                
                // open table and print header
                $renderer->doc .= "<table class=\"inline\">\n";
                $renderer->doc .= "  <tbody>\n";
                $renderer->doc .= "    <tr>\n";
                $renderer->doc .= "      <th>" . $lang['user'] . "</th>\n";
                $renderer->doc .= "      <th>" . $lang['fullname'] . "</th>\n";
                $renderer->doc .= "      <th>" . $lang['email'] . "</th>\n";
                // loop through available groups
                foreach ($grplst as $g) {
                    $renderer->doc .= "      <th>" . htmlspecialchars($g) . "</th>\n";
                }
                $renderer->doc .= "    </tr>\n";
                
                // loop through users
                foreach ($users as $name => $u) {
                    // print user info
                    $renderer->doc .= "    <tr>\n";
                    $renderer->doc .= "      <td>" . htmlspecialchars($name);
                    // need tag so user isn't pulled out of a group if it was added
                    // between initial page load and update
                    // use MD5 hash to take care of formatting issues
                    $hn = md5($name);
                    $renderer->doc .= "<input type=\"hidden\" name=\"id_" . $hn . "\" value=\"1\" />";
                    $renderer->doc .= "</td>\n";
                    $renderer->doc .= "      <td>" . htmlspecialchars($u['name']) . "</td>\n";
                    $renderer->doc .= "      <td>";
                    $renderer->emaillink($u['mail']);
                    $renderer->doc .= "</td>\n";
                    // loop through groups
                    foreach ($grplst as $g) {
                        $renderer->doc .= "      <td>";
                        
                        $chk = "chk_" . $hn . "_" . md5($g);
                        
                        // does this box need to be disabled?
                        // prevents user from taking himself out of an important group
                        $disabled = 0;
                        // if this box applies to a current group membership of the current user, continue check
                        if (in_array($g, $u['grps']) && $_SERVER['REMOTE_USER'] == $name) {
                            // if user is an admin and group is an admin group, disable
                            if ($INFO['isadmin'] && in_array($g, $admingrplst)) {
                                $disabled = 1;
                                // if user was authenticated by this group, disable
                            } else if (strlen($authbygrp) > 0 && $g == $authbygrp) {
                                $disabled = 1;
                            }
                        }
                        
                        // update user group membership
                        // only update if something changed
                        // keep track of status
                        $update = array();
                        if (!$disabled && $_POST["id_" . $hn]) {
                            if ($_POST[$chk]) {
                                if (!in_array($g, $u['grps'])) {
                                    $u['grps'][] = $g;
                                    $update['grps'] = $u['grps'];
                                }
                            } else {
                                if (in_array($g, $u['grps'])) {
                                    $u['grps'] = remove_item_by_value($g, $u['grps'], false);
                                    $update['grps'] = $u['grps'];
                                }
                            }
                            if (count($update) > 0) {
                                if ($auth->modifyUser($name, $update)) {
                                    if ($status == 0) $status = 1;
                                } else {
                                    $status = 2;
                                }
                            }
                        }
                        
                        // display check box
                        $renderer->doc .= "<input type=\"checkbox\" name=\"" . $chk . "\"";
                        if (in_array($g, $u['grps'])) {
                            $renderer->doc .= " checked=\"true\"";
                        }
                        if ($disabled) {
                            $renderer->doc .= " disabled=\"true\"";
                        }
                        
                        $renderer->doc .= " />";
                        
                        $renderer->doc .= "</td>\n";
                    }
                    $renderer->doc .= "    </tr>\n";
                }
                
                $renderer->doc .= "  </tbody>\n";
                $renderer->doc .= "</table>\n";
                
                // update button
                $renderer->doc .= "<div><input class=\"button\" type=\"submit\" value=\"" . $lang['btn_update'] . "\" /></div>";
                
                $renderer->doc .= "</form>";
                
                // display relevant status message
                if ($status == 1) {
                    msg($this->lang['updatesuccess'], 1);
                } else if ($status == 2) {
                    msg($this->lang['updatefailed'], -1);
                }
                
            } else {
                // not authorized
                $renderer->doc .= "<p>" . $this->lang['notauthorized'] . "</p>\n";
            }
            
            return true;
        }
        return false;
    }
}

// vim:ts=4:sw=4:et:enc=utf-8:
