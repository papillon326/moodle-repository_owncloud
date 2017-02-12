<?php

// This file is part of Moodle - http://moodle.org/
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
 * This plugin is used to access owncloud files
 *
 * @package    repository_owncloud
 * @copyright  
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once($CFG->dirroot . '/repository/lib.php');
require_once($CFG->libdir . '/webdavlib.php');
require_once __DIR__ . '/crypt.php';

/**
 * repository_owncloud class
 *
 * @package    repository_owncloud
 * @copyright  
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository_owncloud extends repository {
    const OC_ACCOUNTDB = 'repository_owncloud_accounts';
    
    private $oc_username = '';
    private $oc_password = '';
    private $oc_rootpath = '';
    private $isloggedin = false;
    
    public function __construct($repositoryid, $context = SYSCONTEXTID, $options = array()) {
        parent::__construct($repositoryid, $context, $options);
        // set up webdav client param
        $oc_webdavurl = $this->options['oc_webdavurl'];
        $oc_webdavurl = (object) parse_url($oc_webdavurl);
        
        if ($oc_webdavurl === false) return;
        
        $this->oc_server = $oc_webdavurl->host;
        
        $this->oc_type = '';
        if ($oc_webdavurl->scheme == 'https') {
            $this->oc_type = 'ssl://';
        }

        if (empty($oc_webdavurl->port)) {
            $port = '';
            if (empty($this->oc_type)) {
                $this->oc_port = 80;
            } else {
                $this->oc_port = 443;
                $port = ':443';
            }
        } else {
            $this->oc_port = $oc_webdavurl->port;
            $port = ':' . $this->oc_port;
        }
        
        $this->oc_rootpath = rtrim($oc_webdavurl->path, '/ ');
        $this->oc_host = $this->oc_type . $this->oc_server . $port;
    }

    public function check_login() {
        global $CFG, $DB, $USER;
        
        if ($this->isloggedin === true) return true;
        
        // get login info
        $cond = array(
                'instanceid' => $this->instance->id,
                'userid' => $USER->id,
            );
        $ocaccount = $DB->get_record(self::OC_ACCOUNTDB, $cond);
        
        if ($ocaccount) {
            $this->oc_username = $ocaccount->username;
            $this->oc_password = decrypt($ocaccount->password, $this->instance->name);
        } else {
            $this->oc_username = optional_param('oc_username', '', PARAM_RAW);
            $this->oc_password = optional_param('oc_password', '', PARAM_RAW);
        }
        
        // login attempt
        $this->dav = new webdav_client($this->oc_server, $this->oc_username, $this->oc_password,
            'basic', $this->oc_type);
        $this->dav->port = $this->oc_port;
        $this->dav->debug = false;
        
        // check logged in
        $this->dav->open();
        $resp = $this->dav->ls($this->oc_rootpath . '/');
        if ($resp == '401' || strpos($resp->status, '404 Not Found') !== false) {
            if ($ocaccount) {
                // delete invalid password
                $cond = array(
                        'instanceid' => $this->instance->id,
                        'userid' => $USER->id,
                        'username' => $this->oc_username,
                    );
                $DB->delete_records(self::OC_ACCOUNTDB, $cond);
            }
            
            return false;
        }
        
        // save valid password
        if (!$ocaccount) {
            $ocaccount = new stdClass();
            $ocaccount->instanceid = $this->instance->id;
            $ocaccount->userid   = $USER->id;
            $ocaccount->username = $this->oc_username;
            $ocaccount->password = encrypt($this->oc_password, $this->instance->name);
            $insertid = $DB->insert_record(self::OC_ACCOUNTDB, $ocaccount);
        }
        $this->isloggedin = true;
        
        return true;
    }
    
    public function get_file($url, $title = '') {
        $this->check_login();
        $url = urldecode($url);
        $path = $this->prepare_file();
        if (!$this->dav->open()) {
            return false;
        }
        $this->dav->get_file($this->oc_rootpath . $url, $path);
        return array('path'=>$path);
    }

    public function global_search() {
        return false;
    }
    
    public function get_listing($path='', $page = '') {
        global $CFG, $OUTPUT;
        
        if ($this->check_login() === false) $this->print_login();
        
        $list = array();
        $ret  = array();
        $ret['dynload'] = true;
        $ret['nosearch'] = true;
        $ret['nologin'] = true;
        $ret['path'] = array(array('name'=>get_string('owncloud', 'repository_owncloud'), 'path'=>''));
        $ret['list'] = array();
        if (!$this->dav->open()) {
            return $ret;
        }
        if (empty($path) || $path =='/') {
            $path = '/';
        } else {
            $chunks = preg_split('|/|', trim($path, '/'));
            for ($i = 0; $i < count($chunks); $i++) {
                $ret['path'][] = array(
                    'name' => urldecode($chunks[$i]),
                    'path' => '/'. join('/', array_slice($chunks, 0, $i+1)). '/'
                );
            }
        }
        $dir = $this->dav->ls($this->oc_rootpath . urldecode($path));
        if (!is_array($dir)) {
            return $ret;
        }
        $folders = array();
        $files = array();
        foreach ($dir as $v) {
            if (!empty($v['lastmodified'])) {
                $v['lastmodified'] = strtotime($v['lastmodified']);
            } else {
                $v['lastmodified'] = null;
            }

            // Remove the server URL from the path (if present), otherwise links will not work - MDL-37014
            $server = preg_quote($this->oc_server);
            $v['href'] = preg_replace("#https?://{$server}#", '', $v['href']);
            // Extracting object title from absolute path
            $v['href'] = substr(urldecode($v['href']), strlen($this->oc_rootpath));
            $title = substr($v['href'], strlen($path));

            if (!empty($v['resourcetype']) && $v['resourcetype'] == 'collection') {
                // a folder
                if ($path != $v['href']) {
                    $folders[strtoupper($title)] = array(
                        'title' => rtrim($title, '/'),
                        'thumbnail' => $OUTPUT->pix_url(file_folder_icon(90))->out(false),
                        'children' => array(),
                        'datemodified' => $v['lastmodified'],
                        'path' => $v['href']
                    );
                }
            }else{
                // a file
                $size = !empty($v['getcontentlength'])? $v['getcontentlength']:'';
                $files[strtoupper($title)] = array(
                    'title' => $title,
                    'thumbnail' => $OUTPUT->pix_url(file_extension_icon($title, 90))->out(false),
                    'size' => $size,
                    'datemodified' => $v['lastmodified'],
                    'source' => $v['href']
                );
            }
        }
        ksort($files);
        ksort($folders);
        $ret['list'] = array_merge($folders, $files);
        return $ret;
    }

    public static function get_instance_option_names() {
        return array('oc_webdavurl');
    }

    public static function instance_config_form($mform) {
        $mform->addElement('text', 'oc_webdavurl', get_string('oc_webdavurl', 'repository_owncloud'), array('size' => '80'));
        $mform->addRule('oc_webdavurl', get_string('required'), 'required', null, 'client');
        $mform->setType('oc_webdavurl', PARAM_TEXT);
        $mform->setDefault('oc_webdavurl', 'https://foo.bar.baz/remote.php/webdav/');
    }

    /**
     * Validate repository plugin instance form
     *
     * @param moodleform $mform moodle form
     * @param array $data form data
     * @param array $errors errors
     * @return array errors
     */
    public static function instance_form_validation($mform, $data, $errors) {
        // check valid owncloud URL or not
        $webdavurl = $data['oc_webdavurl'];
        $webdavurl = str_replace('/remote.php/webdav', '/status.php', $webdavurl);
        $webdavurl = rtrim($webdavurl, '/ ');
        $context = stream_context_create(array(
            'http' => array('ignore_errors' => true)
        ));
        $status = (object) json_decode(@file_get_contents($webdavurl, false, $context));  // TODO: trim @
        if (empty($status) || empty($status->installed) || $status->installed === false) {
            $errors['oc_webdavurl'] = get_string('invalidwebdavurl');
        }
        return $errors;
    }

    public function print_login() {
        if ($this->options['ajax']) {
            $user_field = new stdClass();
            $user_field->label = get_string('username').': ';
            $user_field->id    = 'owncloud_username';
            $user_field->type  = 'text';
            $user_field->name  = 'oc_username';
            
            $passwd_field = new stdClass();
            $passwd_field->label = get_string('password').': ';
            $passwd_field->id    = 'owncloud_password';
            $passwd_field->type  = 'password';
            $passwd_field->name  = 'oc_password';
            
            $ret = array();
            $ret['login'] = array($user_field, $passwd_field);
            return $ret;
        } else {
            echo '<table>';
            echo '<tr><td><label>' . get_string('username') . '</label></td>';
            echo '<td><input type="text" name="oc_username" /></td></tr>';
            echo '<tr><td><label>' . get_string('password') . '</label></td>';
            echo '<td><input type="password" name="oc_password" /></td></tr>';
            echo '</table>';
            echo '<input type="submit" value="' . get_string('submit') . '" />';
        }
    }

    public function supported_returntypes() {
        return (FILE_INTERNAL | FILE_EXTERNAL);
    }

    /**
     * Is this repository accessing private data?
     *
     * @return bool
     */
    public function contains_private_data() {
        return false;
    }
}
