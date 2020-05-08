<?php
namespace NinjaFileManager\File_manager;

defined('ABSPATH') || exit;

/**
 * Settings Page
 */

class FileManager
{
    protected static $instance = null;
    
    /**
     *
     * @var object $options The object of the options class
     *
     * */
    public $options;
    public $fmCapability;
    
    public static function getInstance()
    {
        if (null == self::$instance) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    private function __construct()
    {
        // Loading Options
        // Options
		$this->options = get_option('njt-fm-settings');
        if(empty($this->options)) {
            $this->options = array( // Setting up default values
                'file_manager_settings' => array(
                    'root_folder_path' =>  ABSPATH,
                    'root_folder_url' => site_url()
                ),
            );
        }
        register_shutdown_function(array($this, 'saveOptions'));

        add_action('init', array($this, 'isAlowUserAccess'));
        add_action('admin_enqueue_scripts', array($this, 'enqueueAdminScripts'));
        if ($this->isAlowUserAccess()) {
            add_action('admin_menu', array($this, 'FileManager'));
            add_action('wp_ajax_connector', array($this, 'connector'));
            add_action('wp_ajax_selector_themes', array($this, 'selectorThemes'));
            add_action('wp_ajax_get_role_restrictions', array($this, 'getArrRoleRestrictions'));
       }
    }

    public function isAlowUserAccess()
    {
        $user = wp_get_current_user();
        if($user && $user->roles && $user->roles[0]) {
            $allowed_roles = !empty($this->options['file_manager_settings']['list_user_alow_access']) ? $this->options['file_manager_settings']['list_user_alow_access'] : array();
            if( in_array($user->roles[0],$allowed_roles) || $user->roles[0] == 'administrator') {
                $this->fmCapability = $user->roles[0];
                return true;
            }
        }
        $this->fmCapability = 'read';
        return false;
    }

    public function FileManager()
    {

        add_menu_page(
            __('Custom Menu Title', 'textdomain'),
            'File Manager',
            $this->fmCapability,
            'custompage',
            array($this, 'ffmViewFileCallback'),
            '',
            9
        );
        
        add_submenu_page (
          'custompage',
          'Settings',
          'Settings', 
          'manage_options', 
          'plugin-options-general-settings',
          array($this, 'ffmSettingsPage') );
       
    }

    public function ffmViewFileCallback()
    {
        $viewPath = NJT_FM_BN_PLUGIN_PATH . 'views/pages/html-filemanager.php';
        include_once $viewPath;
    }

    public function ffmSettingsPage()
    {
        $viewPath = NJT_FM_BN_PLUGIN_PATH . 'views/pages/html-filemanager-settings.php';
        include_once $viewPath;
    }

    public function enqueueAdminScripts()
    {
        if (empty(get_option('selector_themes'))) {
            update_option('selector_themes', 'Default');
        }
        $selectedTheme = get_option('selector_themes');

        //elfinder css
        wp_enqueue_style('elfinder.jq.css', plugins_url('/lib/jquery/jquery-ui-1.12.0.css', __FILE__));
        wp_enqueue_style('elfinder.full.css', plugins_url('/lib/css/elfinder.full.css', __FILE__));
        wp_enqueue_style('elfinder.min.css', plugins_url('/lib/css/elfinder.min.css', __FILE__));
        wp_enqueue_style('themes-selector', plugins_url('/lib/themes/' . $selectedTheme . '/css/theme.css', __FILE__));
        wp_enqueue_style('themes', plugins_url('/lib/css/theme.css', __FILE__));
       
        //elfinder core
        wp_enqueue_script('jquery_min', plugins_url('/lib/jquery/jquery-ui.min.js', __FILE__));
        wp_enqueue_script('elFinderd', plugins_url('/lib/js/elfinder.full.js', __FILE__));
        wp_enqueue_script('elfinder_editor', plugins_url('/lib/js/extras/editors.default.js', __FILE__));
        //js load fm_locale
        if(isset($this->options['file_manager_settings']['fm_locale'])) {
            $locale = $this->options['file_manager_settings']['fm_locale'];
            if($locale != 'en') {
                wp_enqueue_script( 'fma_lang', plugins_url('lib/js/i18n/elfinder.'.$locale.'.js', __FILE__));
            }
        }
        //elfinder js, css custom
        wp_register_style('file_manager_admin_css',NJT_FM_BN_PLUGIN_URL . 'assets/css/file_manager_admin.css');
        wp_enqueue_style('file_manager_admin_css');
        wp_enqueue_script('file_manager_admin', NJT_FM_BN_PLUGIN_URL . 'assets/js/file_manager_admin.js', array('jquery'), NJT_FM_BN_VERSION);
        wp_localize_script('file_manager_admin', 'wpData', array(
            'admin_ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce("njt-file-manager-admin"),
        ));
    }

    //File manager connector function

    public function connector()
    {
        if( isset( $_POST ) && !empty( $_POST ) && ! wp_verify_nonce( $_POST['nonce'] ,'file-manager-security-token') ) wp_die();
        $uploadMaxSize = isset($this->options['file_manager_settings']['upload_max_size']) && !empty($this->options['file_manager_settings']['upload_max_size']) ? $this->options['file_manager_settings']['upload_max_size'] : 0;

        $opts = array(
            'bind' => array(
                'put.pre' => array(new \FMPHPSyntaxChecker, 'checkSyntax'), // Syntax Checking.
            ),
            'roots' => array(
                array(
                    'driver' => 'LocalFileSystem',
                    'path' => isset($this->options['file_manager_settings']['root_folder_path']) && !empty($this->options['file_manager_settings']['root_folder_path']) ? $this->options['file_manager_settings']['root_folder_path'] : ABSPATH,
                    'URL' => isset($this->options['file_manager_settings']['root_folder_url']) && !empty($this->options['file_manager_settings']['root_folder_url']) ? $this->options['file_manager_settings']['root_folder_url'] :site_url(),
                    'trashHash'     => '', // default is empty, when not enable trash
                    'uploadMaxSize' =>  $uploadMaxSize .'M',
                    'winHashFix'    => DIRECTORY_SEPARATOR !== '/', 
                    'uploadDeny'    => array(), 
                    'uploadAllow'   => array('all'),
                    'uploadOrder'   => array('deny', 'allow'),
                    'accessControl' => 'access',
                    'disabled' => array(''),
                    'attributes' => array() // default is empty
                ),
            ),
        );
        // .htaccess
        if(isset($this->options['file_manager_settings']['enable_htaccess']) && ($this->options['file_manager_settings']['enable_htaccess'] == '1')) {
            $attributes = array(
                'pattern' => '/.htaccess/',
                'read' => false,
                'write' => false,
                'hidden' => true,
                'locked' => false
            );
            array_push($opts['roots'][0]['attributes'], $attributes);
        }

        //Enable Trash
        if(isset($this->options['file_manager_settings']['enable_trash']) && ($this->options['file_manager_settings']['enable_trash'] == '1')) {
            $trash = array(
                'id'            => '1',
                'driver'        => 'Trash',
                'path'          => NJT_FM_BN_PLUGIN_PATH.'includes/File_manager/lib/files/.trash/',
                'tmbURL'        => site_url() . '/includes/File_manager/lib/files/.trash/.tmb',
                'winHashFix'    => DIRECTORY_SEPARATOR !== '/', 
                'uploadDeny'    => array(), 
                'uploadAllow'   => array('all'),
                'uploadOrder'   => array('deny', 'allow'),
                'accessControl' => 'access',
                'attributes' => array(
                    array(
                        'pattern' => '/.tmb/',
                        'read' => false,
                        'write' => false,
                        'hidden' => true,
                        'locked' => false
                    ),
                    array(
                        'pattern' => '/.gitkeep/',
                        'read' => false,
                        'write' => false,
                        'hidden' => true,
                        'locked' => false
                    )
                )
            );
            $opts['roots'][0]['trashHash'] = 't1_Lw';
            $opts['roots'][1] = $trash;
        }

        //Start --setting User Role Restrictions
        $user = wp_get_current_user();
        $userRoles =  $user && $user->roles && $user->roles[0] ? $user->roles[0] : '';
        
        //Disable Operations
        if(!empty($this->options['file_manager_settings']['list_user_role_restrictions'][$userRoles]['list_user_restrictions_alow_access'])){
            $opts['roots'][0]['disabled'] = $this->options['file_manager_settings']['list_user_role_restrictions'][$userRoles]['list_user_restrictions_alow_access'];
        }
        //Seperate or private folder access
        if(!empty($this->options['file_manager_settings']['list_user_role_restrictions'][$userRoles]['private_folder_access'])){
            $opts['roots'][0]['path'] = ABSPATH .$this->options['file_manager_settings']['list_user_role_restrictions'][$userRoles]['private_folder_access'] .'/';
        }

        //Folder or File Paths That You want to Hide
        if(!empty($this->options['file_manager_settings']['list_user_role_restrictions'][$userRoles]['hide_paths'])){
            foreach ($this->options['file_manager_settings']['list_user_role_restrictions'][$userRoles]['hide_paths'] as $key => $value){
                $arrItemHidePath =  array( 
                     'pattern' => '~/'.$value.'~',
                     'read' => false,
                     'write' => false,
                     'hidden' => true,
                     'locked' => false
                   );
                   array_push($opts['roots'][0]['attributes'], $arrItemHidePath);
               };
        }

        //File extensions which you want to Lock
        if(!empty($this->options['file_manager_settings']['list_user_role_restrictions'][$userRoles]['lock_files'])){
            foreach ($this->options['file_manager_settings']['list_user_role_restrictions'][$userRoles]['lock_files'] as $key => $value){
                $arrItemLockFile =  array( 
                     'pattern' => '/'.$value.'/',
                     'read' => false,
                     'write' => false,
                     'hidden' => false,
                     'locked' => true
                   );
                   array_push($opts['roots'][0]['attributes'], $arrItemLockFile);
               };
        }

        //Enter file extensions which can be uploaded
        if(!empty($this->options['file_manager_settings']['list_user_role_restrictions'][$userRoles]['can_upload_mime'])){
            $opts['roots'][0]['uploadDeny'] = array('all');
            $opts['roots'][0]['uploadAllow'] = array(); 
            foreach ($this->options['file_manager_settings']['list_user_role_restrictions'][$userRoles]['can_upload_mime'] as $key => $value){
                array_push($opts['roots'][0]['uploadAllow'], $value);
            };
        }
        //End --setting User Role Restrictions

        $connector = new \elFinderConnector(new \elFinder($opts));
        $connector->run();
        wp_die();
    }
    
    public function selectorThemes()
    {
        if( ! wp_verify_nonce( $_POST['nonce'] ,'njt-file-manager-admin')) wp_die();
        check_ajax_referer('njt-file-manager-admin', 'nonce', true);
        $themesValue = sanitize_text_field ($_POST['themesValue']);
        $selector_themes = get_option('selector_themes');
        if (empty($selector_themes) || $selector_themes != $themesValue) {
            update_option('selector_themes', $themesValue);
        }
        $selected_themes = get_option('selector_themes');
        $linkThemes = plugins_url('/lib/themes/' . $themesValue . '/css/theme.css', __FILE__);
        wp_send_json_success($linkThemes);
        wp_die();
    }

    public function saveOptions()
    {
		update_option('njt-fm-settings', $this->options);
    }
    
    public function getArrRoleRestrictions()
    {
        if(!wp_verify_nonce( $_POST['nonce'] ,'njt-file-manager-admin')) wp_die();
        check_ajax_referer('njt-file-manager-admin', 'nonce', true);
        $valueUserRole = filter_var($_POST['valueUserRole']) ? sanitize_text_field ($_POST['valueUserRole']) : '';
        $arrRestrictions = !empty($this->options['file_manager_settings']['list_user_role_restrictions']) ? $this->options['file_manager_settings']['list_user_role_restrictions'] : array();
        $dataArrRoleRestrictions = array (
            'disable_operations' => implode(",", !empty($arrRestrictions[$valueUserRole]['list_user_restrictions_alow_access']) ? $arrRestrictions[$valueUserRole]['list_user_restrictions_alow_access'] : array()),
            'private_folder_access' => !empty($arrRestrictions[$valueUserRole]['private_folder_access']) ? str_replace("\\\\", "/", trim($arrRestrictions[$valueUserRole]['private_folder_access'])) : '',
            'hide_paths' => implode(',', !empty($arrRestrictions[$valueUserRole]['hide_paths']) ? $arrRestrictions[$valueUserRole]['hide_paths'] : array()),
            'lock_files' => implode(',', !empty($arrRestrictions[$valueUserRole]['lock_files']) ? $arrRestrictions[$valueUserRole]['lock_files'] : array()),
            'can_upload_mime' => implode(',', !empty($arrRestrictions[$valueUserRole]['can_upload_mime']) ? $arrRestrictions[$valueUserRole]['can_upload_mime'] : array())
        );
        wp_send_json_success($dataArrRoleRestrictions);
        wp_die();
    }

}