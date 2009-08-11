<?php

/* Determine the base directories. */
$curr_dir = dirname(__FILE__);

if (!defined('IMP_BASE')) {
    define('IMP_BASE', $curr_dir . '/..');
}

if (!defined('HORDE_BASE')) {
    /* If Horde does not live directly under the app directory, the HORDE_BASE
     * constant should be defined in config/horde.local.php. */
    if (file_exists(IMP_BASE . '/config/horde.local.php')) {
        include IMP_BASE . '/config/horde.local.php';
    } else {
        define('HORDE_BASE', $curr_dir . '/../..');
    }
}

/* Load the Horde Framework core (needed to autoload
 * Horde_Registry_Application::). */
require_once HORDE_BASE . '/lib/core.php';

/**
 * IMP application API.
 *
 * This file defines IMP's external API interface. Other applications
 * can interact with IMP through this API.
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/gpl.html.
 *
 * @package IMP
 */
class IMP_Application extends Horde_Registry_Application
{
    /**
     * Does this application support a mobile view?
     *
     * @var boolean
     */
    public $mobileView = true;

    /**
     * The application's version.
     *
     * @var string
     */
    public $version = 'H4 (5.0-git)';

    /**
     * The auth type to use.
     *
     * @var string
     */
    static public $authType = null;

    /**
     * Disable compression of pages?
     *
     * @var boolean
     */
    static public $noCompress = false;

    /**
     * Constructor.
     *
     * @param array $args  The following entries:
     * <pre>
     * 'init' - (boolean|array) If true, perform application init. If an
     *          array, perform application init and pass the array to init().
     * </pre>
     */
    public function __construct($args = array())
    {
        if (!empty($args['init'])) {
            $this->init(is_array($args['init']) ? $args['init'] : array());
        }

        /* Only available if admin config is set for this server/login. */
        $this->disabled = array('init');
        if (empty($_SESSION['imp']['admin'])) {
            $this->disabled = array_merge($this->disabled, array('authAddUser', 'authRemoveUser', 'authUserList'));
        }
    }

    /**
     * IMP base initialization.
     *
     * Global variables defined:
     *   $imp_imap    - An IMP_Imap object
     *   $imp_mbox    - Current mailbox information
     *   $imp_notify  - A Horde_Notification_Listener object
     *   $imp_search  - An IMP_Search object
     *
     * @param array $args  Optional arguments:
     * <pre>
     * 'authentication' - (string) The type of authentication to use:
     *   'horde' - Only use horde authentication
     *   'none'  - Do not authenticate
     *   'throw' - Authenticate to IMAP/POP server; on no auth, throw a
     *             Horde_Exception
     *   [DEFAULT] - Authenticate to IMAP/POP server; on no auth redirect to
     *               login screen
     * 'no_compress' - (boolean) Controls whether the page should be
     *                 compressed.
     * 'session_control' - (string) Sets special session control limitations:
     *   'netscape' - TODO; start read/write session
     *   'none' - Do not start a session
     *   'readonly' - Start session readonly
     *   [DEFAULT] - Start read/write session
     * </pre>
     */
    public function init($args = array())
    {
        $args = array_merge(array(
            'authentication' => null,
            'nocompress' => false,
            'session_control' => null
        ), $args);

        self::$authType = $args['authentication'];
        self::$noCompress = $args['nocompress'];

        // Registry.
        $s_ctrl = 0;
        switch ($args['session_control']) {
        case 'netscape':
            if ($GLOBALS['browser']->isBrowser('mozilla')) {
                session_cache_limiter('private, must-revalidate');
            }
            break;

        case 'none':
            $s_ctrl = Horde_Registry::SESSION_NONE;
            break;

        case 'readonly':
            $s_ctrl = Horde_Registry::SESSION_READONLY;
            break;
        }
        $GLOBALS['registry'] = Horde_Registry::singleton($s_ctrl);

        try {
            $GLOBALS['registry']->pushApp('imp', array('check_perms' => ($args['authentication'] != 'none'), 'logintasks' => true));
        } catch (Horde_Exception $e) {
            if ($e->getCode() == Horde_Registry::AUTH_FAILURE) {
                if (Horde_Util::getFormData('composeCache')) {
                    $imp_compose = IMP_Compose::singleton();
                    $imp_compose->sessionExpireDraft();
                }

                if ($args['authentication'] == 'throw') {
                    throw $e;
                }
            }

            Horde_Auth::authenticateFailure('imp', $e);
        }

        // All other initialization occurs in IMP::initialize().
        IMP::initialize();
    }

    /* Horde_Auth_Application methods. */

    /**
     * Return login parameters used on the login page.
     *
     * @return array  TODO
     */
    public function authLoginParams()
    {
        $params = array();

        if ($GLOBALS['conf']['server']['server_list'] == 'shown') {
            $servers = IMP_Imap::loadServerConfig();
            $server_list = array();
            $selected = Horde_Util::getFormData('imp_server_key', IMP_Auth::getAutoLoginServer());
            foreach ($servers as $key => $val) {
                $server_list[$key] = array(
                    'name' => $val['name'],
                    'selected' => ($selected == $key)
                );
            }
            $params['imp_server_key'] = array(
                'label' => _("Server"),
                'type' => 'select',
                'value' => $server_list
            );
        }

        /* Show selection of alternate views. */
        if (!empty($GLOBALS['conf']['user']['select_view'])) {
            $views = array();
            if (!($view_cookie = Horde_Util::getFormData('imp_select_view'))) {
                if (isset($_COOKIE['default_imp_view'])) {
                    $view_cookie = $_COOKIE['default_imp_view'];
                } else {
                    $browser = Horde_Browser::singleton();
                    $view_cookie = $browser->isMobile() ? 'mimp' : 'imp';
                }
            }

            $params['imp_select_view'] = array(
                'label' => _("Mode"),
                'type' => 'select',
                'value' => array(
                    'imp' => array(
                        'name' => _("Traditional"),
                        'selected' => $view_cookie == 'imp'
                    ),
                    'dimp' => array(
                        'hidden' => true,
                        'name' => _("Dynamic")
                        // Dimp selected is handled by javascript (dimp_sel)
                    ),
                    'mimp' => array(
                        'name' => _("Minimalist"),
                        'selected' => $view_cookie == 'mimp'
                    )
                )
            );
        }

        return array(
            'js_code' => array(
                'ImpLogin.dimp_sel=' . intval($view_cookie == 'dimp'),
                'ImpLogin.server_key_error=' . Horde_Serialize::serialize(_("Please choose a mail server."), Horde_Serialize::JSON)
            ),
            'js_files' => array(
                array('login.js', 'imp')
            ),
            'params' => $params
        );
    }

    /**
     * Tries to authenticate with the mail server and create a mail session.
     *
     * @param string $userId      The username of the user.
     * @param array $credentials  Credentials of the user. Allowed keys:
     *                            'imp_select_view', 'imp_server_key',
     *                            'password'.
     *
     * @throws Horde_Auth_Exception
     */
    public function authAuthenticate($userId, $credentials)
    {
        $this->init(array('authentication' => 'none'));

        $new_session = IMP_Auth::authenticate(array(
            'password' => $credentials['password'],
            'server' => empty($credentials['imp_server_key']) ? IMP_Auth::getAutoLoginServer() : $credentials['imp_server_key'],
            'userId' => $userId
        ));

        if ($new_session) {
            $_SESSION['imp']['cache']['select_view'] = empty($credentials['imp_select_view'])
                ? ''
                : $credentials['imp_select_view'];

            /* Set the Horde ID, since it may have been altered by the 'realm'
             * setting. */
            $credentials['auth_ob']->setCredential('userId', $_SESSION['imp']['uniquser']);
        }
    }

    /**
     * Tries to transparently authenticate with the mail server and create a
     * mail session.
     *
     * @return boolean  Whether transparent login is supported.
     * @throws Horde_Auth_Exception
     */
    public function authTransparent()
    {
        $this->init(array('authentication' => 'none'));
        return IMP_Auth::transparent();
    }

    /**
     * Does necessary authentication tasks reliant on a full IMP environment.
     *
     * @throws Horde_Auth_Exception
     */
    public function authAuthenticateCallback()
    {
        if (Horde_Auth::getAuth()) {
            $this->init();
            IMP_Auth::authenticateCallback();
        }
    }

    /**
     * Adds a user defined by authentication credentials.
     *
     * @param string $userId      The userId to add.
     * @param array $credentials  An array of login credentials. For IMAP,
     *                            this must contain a password entry.
     *
     * @throws Horde_Exception
     */
    public function authAddUser($userId, $credentials)
    {
        $params = $GLOBALS['registry']->callByPackage('imp', 'server');
        if (is_null($params)) {
            return;
        }

        $params = array_merge($params, $_SESSION['imp']['admin']['params']);
        if (isset($params['admin_password'])) {
            $params['admin_password'] = Horde_Secret::read(Horde_Secret::getKey('imp'), $params['admin_password']);
        }
        $auth = Horde_Auth::singleton('imap', $params);
        $auth->addUser($userId, $credentials);
    }

    /**
     * Deletes a user defined by authentication credentials.
     *
     * @param string $userId  The userId to delete.
     *
     * @throws Horde_Exception
     */
    public function authRemoveUser($userId)
    {
        $params = $GLOBALS['registry']->callByPackage('imp', 'server');
        if (is_null($params)) {
            return;
        }

        $params = array_merge($params, $_SESSION['imp']['admin']['params']);
        if (isset($params['admin_password'])) {
            $params['admin_password'] = Horde_Secret::read(Horde_Secret::getKey('imp'), $params['admin_password']);
        }
        $auth = Horde_Auth::singleton('imap', $params);
        $auth->removeUser($userId);
    }

    /**
     * Lists all users in the system.
     *
     * @return array  The array of userIds.
     * @throws Horde_Exception
     */
    public function authUserList()
    {
        $params = $GLOBALS['registry']->callByPackage('imp', 'server');
        if (is_null($params)) {
            return;
        }

        $params = array_merge($params, $_SESSION['imp']['admin']['params']);
        if (isset($params['admin_password'])) {
            $params['admin_password'] = Horde_Secret::read(Horde_Secret::getKey('imp'), $params['admin_password']);
        }
        $auth = Horde_Auth::singleton('imap', $params);
        return $auth->listUsers();
    }

    /* Preferences display/handling methods. */

    /**
     * Code to run when viewing prefs for this application.
     *
     * @param string $group  The prefGroup name.
     *
     * @return array  A list of variables to export to the prefs display page.
     */
    public function prefsInit($group)
    {
        /* Add necessary javascript files here (so they are added to the
         * document HEAD). */
        switch ($group) {
        case 'flags':
            Horde::addScriptFile('colorpicker.js', 'horde', true);
            Horde::addScriptFile('flagmanagement.js', 'imp', true);

            Horde::addInlineScript(array(
                'ImpFlagmanagement.new_prompt = ' . Horde_Serialize::serialize(_("Please enter the label for the new flag:"), Horde_Serialize::JSON, Horde_Nls::getCharset()),
                'ImpFlagmanagement.confirm_delete = ' . Horde_Serialize::serialize(_("Are you sure you want to delete this flag?"), Horde_Serialize::JSON, Horde_Nls::getCharset())
            ));
            break;
        }
    }

    /**
     * Special preferences handling on update.
     *
     * @param string $item      The preference name.
     * @param boolean $updated  Set to true if preference was updated.
     *
     * @return boolean  True if preference was updated.
     */
    public function prefsHandle($item, $updated)
    {
        switch ($item) {
        case 'sentmailselect':
            return $this->_prefsSentmailSelect($updated);

        case 'draftsselect':
            return $updated | $this->_prefsHandleFolders($updated, 'drafts_folder', 'drafts', 'drafts_new');

        case 'spamselect':
            return $updated | $this->_prefsHandleFolders($updated, 'spam_folder', 'spam', 'spam_new');

        case 'trashselect':
            return $this->_prefsTrashSelect($updated);

        case 'sourceselect':
            return $this->_prefsSourceSelect($updated);

        case 'initialpageselect':
            $this->_prefsInitialPageSelect();
            return true;

        case 'encryptselect':
            $this->_prefsEncryptSelect();
            return true;

        case 'defaultsearchselect':
            $this->_prefsDefaultSearchSelect();
            return true;

        case 'soundselect':
            return $GLOBALS['prefs']->setValue('nav_audio', Horde_Util::getFormData('nav_audio'));

        case 'flagmanagement':
            $this->_prefsFlagManagement();
            return false;
        }
    }

    /**
     * Do anything that we need to do as a result of certain preferences
     * changing.
     */
    public function prefsCallback()
    {
        global $prefs;

        /* Always check to make sure we have a valid trash folder if delete to
         * trash is active. */
        if (($prefs->isDirty('use_trash') || $prefs->isDirty('trash_folder')) &&
            $prefs->getValue('use_trash') &&
            !$prefs->getValue('trash_folder') &&
            !$prefs->getValue('use_vtrash')) {
                $GLOBALS['notification']->push(_("You have activated move to Trash but no Trash folder is defined. You will be unable to delete messages until you set a Trash folder in the preferences."), 'horde.warning');
            }

        if ($prefs->isDirty('use_vtrash') || $prefs->isDirty('use_vinbox')) {
            $imp_search = new IMP_Search();
            $imp_search->initialize(true);
        }

        if ($prefs->isDirty('subscribe') || $prefs->isDirty('tree_view')) {
            $imp_folder = IMP_Folder::singleton();
            $imp_folder->clearFlistCache();
            $imaptree = IMP_Imap_Tree::singleton();
            $imaptree->init();
        }

        if ($prefs->isDirty('mail_domain')) {
            $maildomain = preg_replace('/[^-\.a-z0-9]/i', '', $prefs->getValue('mail_domain'));
            $prefs->setValue('maildomain', $maildomain);
            if (!empty($maildomain)) {
                $_SESSION['imp']['maildomain'] = $maildomain;
            }
        }

        if ($prefs->isDirty('compose_popup')) {
            Horde::addInlineScript(array(
                'if (window.parent.frames.horde_menu) window.parent.frames.horde_menu.location.reload();'
            ));
        }
    }

    /**
     * Generate the menu to use on the prefs page.
     *
     * @return Horde_Menu  A Horde_Menu object.
     */
    public function prefsMenu()
    {
        return IMP::getMenu();
    }

    /**
     * Setup notifications handler for the preferences page. This will only
     * be called if in dimp view mode.
     */
    public function prefsStatus()
    {
        require_once dirname(__FILE__) . '/Application.php';
        new IMP_Application(array('init' => array('authentication' => 'none')));

        $notification = Horde_Notification::singleton();
        $notification->detach('status');
        $notification->attach('status', array('prefs' => true, 'viewmode' => 'dimp'), 'IMP_Notification_Listener_Status');
    }

    /**
     * TODO
     */
    protected function _prefsSentmailSelect($updated)
    {
        if (!$GLOBALS['conf']['user']['allow_folders'] ||
            $GLOBALS['prefs']->isLocked('sent_mail_folder')) {
            return $updated;
        }

        $sent_mail_folder = Horde_Util::getFormData('sent_mail_folder');
        $sent_mail_new = Horde_String::convertCharset(Horde_Util::getFormData('sent_mail_new'), Horde_Nls::getCharset(), 'UTF7-IMAP');
        $sent_mail_default = $GLOBALS['prefs']->getValue('sent_mail_folder');

        if (empty($sent_mail_folder) && !empty($sent_mail_new)) {
            $sent_mail_folder = $GLOBALS['imp_imap']->appendNamespace($sent_mail_new);
        } elseif (($sent_mail_folder == '-1') && !empty($sent_mail_default)) {
            $sent_mail_folder = $GLOBALS['imp_imap']->appendNamespace($sent_mail_default);
        }

        if (!empty($sent_mail_folder)) {
            $imp_folder = IMP_Folder::singleton();
            if (!$imp_folder->exists($sent_mail_folder)) {
                $imp_folder->create($sent_mail_folder, $GLOBALS['prefs']->getValue('subscribe'));
            }
        }
        $GLOBALS['identity']->setValue('sent_mail_folder', IMP::folderPref($sent_mail_folder, false));

        return true;
    }

    /**
     * TODO
     */
    protected function _prefsHandlefolders($updated, $pref, $folder, $new)
    {
        if (!$GLOBALS['conf']['user']['allow_folders']) {
            return $updated;
        }

        $folder = Horde_Util::getFormData($folder);
        if (isset($folder) && !$GLOBALS['prefs']->isLocked($pref)) {
            $new = Horde_String::convertCharset(Horde_Util::getFormData($new), Horde_Nls::getCharset(), 'UTF7-IMAP');
            if ($folder == IMP::PREF_NO_FOLDER) {
                $GLOBALS['prefs']->setValue($pref, '');
            } else {
                if (empty($folder) && !empty($new)) {
                    $folder = $GLOBALS['imp_imap']->appendNamespace($new);
                    $imp_folder = IMP_Folder::singleton();
                    if (!$imp_folder->create($folder, $GLOBALS['prefs']->getValue('subscribe'))) {
                        $folder = null;
                    }
                }
                if (!empty($folder)) {
                    $GLOBALS['prefs']->setValue($pref, IMP::folderPref($folder, false));
                    return true;
                }
            }
        }

        return $updated;
    }

    /**
     * TODO
     */
    protected function _prefsTrashSelect($updated)
    {
        global $prefs;

        if (Horde_Util::getFormData('trash') == IMP::PREF_VTRASH) {
            if ($prefs->isLocked('use_vtrash')) {
                return false;
            }

            $prefs->setValue('use_vtrash', 1);
            $prefs->setValue('trash_folder', '');
        } else {
            if ($prefs->isLocked('trash_folder')) {
                return false;
            }

            $updated = $updated | $this->_prefsHandleFolders($updated, 'trash_folder', 'trash', 'trash_new');
            if ($updated) {
                $prefs->setValue('use_vtrash', 0);
                $prefs->setDirty('trash_folder', true);
            }
        }

        return $updated;
    }

    /**
     * TODO
     */
    protected function _prefsSourceSelect($updated)
    {
        $search_sources = Horde_Util::getFormData('search_sources');
        if (!is_null($search_sources)) {
            $GLOBALS['prefs']->setValue('search_sources', $search_sources);
            unset($_SESSION['imp']['cache']['ac_ajax']);
            $updated = true;
        }

        $search_fields_string = Horde_Util::getFormData('search_fields_string');
        if (!is_null($search_fields_string)) {
            $GLOBALS['prefs']->setValue('search_fields', $search_fields_string);
            $updated = true;
        }

        $add_source = Horde_Util::getFormData('add_source');
        if (!is_null($add_source)) {
            $GLOBALS['prefs']->setValue('add_source', $add_source);
            $updated = true;
        }

        return $updated;
    }

    /**
     * TODO
     */
    protected function _prefsInitialPageSelect()
    {
        $initial_page = Horde_Util::getFormData('initial_page');
        $GLOBALS['prefs']->setValue('initial_page', $initial_page);
    }

    /**
     * TODO
     */
    protected function _prefsEncryptSelect()
    {
        $default_encrypt = Horde_Util::getFormData('default_encrypt');
        $GLOBALS['prefs']->setValue('default_encrypt', $default_encrypt);
    }

    /**
     * TODO
     */
    protected function _prefsDefaultSearchSelect()
    {
        $default_search = Horde_Util::getFormData('default_search');
        $GLOBALS['prefs']->setValue('default_search', $default_search);
    }

    /**
     * TODO
     */
    protected function _prefsFlagManagement()
    {
        $imp_flags = IMP_Imap_Flags::singleton();
        $action = Horde_Util::getFormData('flag_action');
        $data = Horde_Util::getFormData('flag_data');

        if ($action == 'add') {
            $imp_flags->addFlag($data);
            return;
        }

        $def_color = $GLOBALS['prefs']->getValue('msgflags_color');

        // Don't set updated on these actions. User may want to do more actions.
        foreach ($imp_flags->getList() as $key => $val) {
            $md5 = hash('md5', $key);

            switch ($action) {
            case 'delete':
                if ($data == ('bg_' . $md5)) {
                    $imp_flags->deleteFlag($key);
                }
                break;

            default:
                /* Change labels for user-defined flags. */
                if ($val['t'] == 'imapp') {
                    $label = Horde_Util::getFormData('label_' . $md5);
                    if (strlen($label) && ($label != $val['l'])) {
                        $imp_flags->updateFlag($key, array('l' => $label));
                    }
                }

                /* Change background for all flags. */
                $bg = strtolower(Horde_Util::getFormData('bg_' . $md5));
                if ((isset($val['b']) && ($bg != $val['b'])) ||
                    (!isset($val['b']) && ($bg != $def_color))) {
                        $imp_flags->updateFlag($key, array('b' => $bg));
                }
                break;
            }
        }
    }

    /* horde/services/cache.php methods. */

    /**
     * Application-specific cache output driver.
     *
     * @param array $params  A list of params needed (USED: 'id').
     *
     * @return array  See Horde::getCacheUrl().
     * @throws Horde_Exception
     */
    public function cacheOutput($params)
    {
        try {
            $this->init(array('authentication' => 'throw'));
        } catch (Horde_Exception $e) {
            throw new Horde_Exception('No cache data available');
        }

        switch ($params['id']) {
        case 'fckeditor':
            return array(
                'data' =>
                    'FCKConfig.ToolbarSets["ImpToolbar"] = ' . $GLOBALS['prefs']->getValue('fckeditor_buttons') . ";\n" .
                    /* To more closely match "normal" textarea behavior, send
                     * send <BR> on enter instead of <P>. */
                    "FCKConfig.EnterMode = 'br';\n" .
                    'FCKConfig.ShiftEnterMode = \'p\';',
                'type' => 'text/javascript'
            );
        }
    }

    /* Language change callback. */

    /**
     * Performs tasks necessary when the language is changed during the
     * session.
     */
    public function changeLanguage()
    {
        try {
            $this->init(array('authentication' => 'throw'));
        } catch (Horde_Exception $e) {
            return;
        }

        $imp_folder = IMP_Folder::singleton();
        $imp_folder->clearFlistCache();
        $imaptree = IMP_Imap_Tree::singleton();
        $imaptree->init();
        $GLOBALS['imp_search']->initialize(true);
    }

}
