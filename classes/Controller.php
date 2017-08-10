<?php
/**
 * @package    Grav.Plugin.Login
 *
 * @copyright  Copyright (C) 2014 - 2017 RocketTheme, LLC. All rights reserved.
 * @license    MIT License; see LICENSE file for details.
 */
namespace Grav\Plugin\Login;

use Grav\Common\Grav;
use Grav\Common\Language\Language;
use Grav\Common\User\User;
use Grav\Common\Utils;
use Grav\Plugin\Email\Utils as EmailUtils;
use RocketTheme\Toolbox\Session\Message;

/**
 * Class Controller
 * @package Grav\Plugin\Login
 */
class Controller
{
    /**
     * @var Grav
     */
    public $grav;

    /**
     * @var string
     */
    public $action;

    /**
     * @var array
     */
    public $post;

    /**
     * @var string
     */
    protected $redirect;

    /**
     * @var int
     */
    protected $redirectCode;

    /**
     * @var string
     */
    protected $prefix = 'task';

    /**
     * @var RememberMe\RememberMe
     */
    protected $rememberMe;

    /**
     * @var Login
     */
    protected $login;

    /**
     * @param Grav   $grav
     * @param string $action
     * @param array  $post
     */
    public function __construct(Grav $grav, $action, $post = null)
    {
        $this->grav = $grav;
        $this->action = $action;
        $this->login = $this->grav['login'];
        $this->post = $this->getPost($post);

        $this->rememberMe();
    }

    /**
     * Performs an action.
     */
    public function execute()
    {
        // Set redirect if available.
        if (isset($this->post['_redirect'])) {
            $redirect = $this->post['_redirect'];
            unset($this->post['_redirect']);
        }

        $success = false;
        $method = $this->prefix . ucfirst($this->action);

        if (!method_exists($this, $method)) {
            throw new \RuntimeException('Page Not Found', 404);
        }

        try {
            $success = call_user_func([$this, $method]);
        } catch (\RuntimeException $e) {
            $this->setMessage($e->getMessage(), 'error');
        }

        if (!$this->redirect && isset($redirect)) {
            $this->setRedirect($redirect);
        }

        return $success;
    }

    /**
     * Handle login.
     *
     * @return bool True if the action was performed.
     */
    public function taskLogin()
    {
        /** @var Language $t */
        $t = $this->grav['language'];

        /** @var User $user */
        $user = $this->grav['user'];

        $count = $this->grav['config']->get('plugins.login.max_login_count', 5);
        $interval = $this->grav['config']->get('plugins.login.max_login_interval', 10);

        if ($this->login->isUserRateLimited($user, 'login_attempts', $count, $interval)) {
            $this->setMessage($t->translate(['PLUGIN_LOGIN.TOO_MANY_LOGIN_ATTEMPTS', $interval]), 'error');
            $this->setRedirect($this->grav['config']->get('plugins.login.route', '/'));

            return true;
        }

        // TODO: The following logic has really never worked as the user object may be changed during the login progress!
        if ($this->authenticate($this->post)) {
            $this->login->setMessage($t->translate('PLUGIN_LOGIN.LOGIN_SUCCESSFUL'));

            $this->login->resetRateLimit($user, 'login_attempts');

            $redirect = $this->grav['config']->get('plugins.login.redirect_after_login');
            if (!$redirect) {
                $redirect = $this->grav['session']->redirect_after_login ?: $this->grav['uri']->referrer('/');
            }
            $this->setRedirect($redirect);
        } elseif ($user->username) {
            $this->setMessage($t->translate('PLUGIN_LOGIN.ACCESS_DENIED'), 'error');
        } else {
            $this->setMessage($t->translate('PLUGIN_LOGIN.LOGIN_FAILED'), 'error');
        }

        return true;
    }

    /**
     * Handle logout.
     *
     * @return bool True if the action was performed.
     */
    public function taskLogout()
    {
        $this->login->logout(['remember_me' => true]);

        $this->setRedirect('/');

        return true;
    }

    /**
     * Handle the email password recovery procedure.
     *
     * @return bool True if the action was performed.
     */
    protected function taskForgot()
    {
        $param_sep = $this->grav['config']->get('system.param_sep', ':');
        $data = $this->post;

        $email = isset($data['email']) ? $data['email'] : '';
        $user = !empty($email) ? User::find($email, ['email']) : null;

        /** @var Language $language */
        $language = $this->grav['language'];
        $messages = $this->grav['messages'];

        if (!isset($this->grav['Email'])) {
            $messages->add($language->translate('PLUGIN_LOGIN.FORGOT_EMAIL_NOT_CONFIGURED'), 'error');
            $this->setRedirect($this->grav['config']->get('plugins.login.route_forgot', '/'));

            return true;
        }

        if (!$user || !$user->exists()) {
            $messages->add($language->translate('PLUGIN_LOGIN.FORGOT_INSTRUCTIONS_SENT_VIA_EMAIL'), 'info');
            $this->setRedirect($this->grav['config']->get('plugins.login.route_forgot', '/'));

            return true;
        }

        if (empty($user->email)) {
            $messages->add($language->translate(['PLUGIN_LOGIN.FORGOT_CANNOT_RESET_EMAIL_NO_EMAIL', $email]),
                'error');
            $this->setRedirect($this->grav['config']->get('plugins.login.route_forgot', '/'));

            return true;
        }

        $from = $this->grav['config']->get('plugins.email.from');

        if (empty($from)) {
            $messages->add($language->translate('PLUGIN_LOGIN.FORGOT_EMAIL_NOT_CONFIGURED'), 'error');
            $this->setRedirect($this->grav['config']->get('plugins.login.route_forgot', '/'));

            return true;
        }

        $count = $this->grav['config']->get('plugins.login.max_pw_resets_count', 0);
        $interval =$this->grav['config']->get('plugins.login.max_pw_resets_interval', 2);

        if ($this->login->isUserRateLimited($user, 'pw_resets', $count, $interval)) {
            $messages->add($language->translate(['PLUGIN_LOGIN.FORGOT_CANNOT_RESET_IT_IS_BLOCKED', $email, $interval]), 'error');
            $this->setRedirect($this->grav['config']->get('plugins.login.route', '/'));

            return true;
        }

        $token = md5(uniqid(mt_rand(), true));
        $expire = time() + 604800; // next week

        $user->reset = $token . '::' . $expire;
        $user->save();

        $author = $this->grav['config']->get('site.author.name', '');
        $fullname = $user->fullname ?: $user->username;

        $reset_link = $this->grav['base_url_absolute'] . $this->grav['config']->get('plugins.login.route_reset') . '/task:login.reset/token' . $param_sep . $token . '/user' . $param_sep . $user->username . '/nonce' . $param_sep . Utils::getNonce('reset-form');

        $sitename = $this->grav['config']->get('site.title', 'Website');

        $to = $user->email;

        $subject = $language->translate(['PLUGIN_LOGIN.FORGOT_EMAIL_SUBJECT', $sitename]);
        $content = $language->translate(['PLUGIN_LOGIN.FORGOT_EMAIL_BODY', $fullname, $reset_link, $author, $sitename]);

        $sent = EmailUtils::sendEmail($subject, $content, $to);

        if ($sent < 1) {
            $messages->add($language->translate('PLUGIN_LOGIN.FORGOT_FAILED_TO_EMAIL'), 'error');
        } else {
            $messages->add($language->translate('PLUGIN_LOGIN.FORGOT_INSTRUCTIONS_SENT_VIA_EMAIL'), 'info');
        }

        $this->setRedirect($this->grav['config']->get('plugins.login.route', '/'));

        return true;
    }

    /**
     * Handle the reset password action.
     *
     * @return bool True if the action was performed.
     */
    public function taskReset()
    {
        $data = $this->post;
        $language = $this->grav['language'];
        $messages = $this->grav['messages'];

        if (isset($data['password'])) {
            $username = isset($data['username']) ? $data['username'] : null;
            $user = !empty($username) ? User::find($username) : null;
            $password = isset($data['password']) ? $data['password'] : null;
            $token = isset($data['token']) ? $data['token'] : null;

            if ($user && !empty($user->reset) && $user->exists()) {
                list($good_token, $expire) = explode('::', $user->reset);

                if ($good_token === $token) {
                    if (time() > $expire) {
                        $messages->add($language->translate('PLUGIN_LOGIN.RESET_LINK_EXPIRED'), 'error');
                        $this->grav->redirect($this->grav['config']->get('plugins.login.route_forgot', '/'));

                        return true;
                    }

                    unset($user->hashed_password);
                    unset($user->reset);
                    $user->password = $password;

                    $user->validate();
                    $user->filter();
                    $user->save();

                    $messages->add($language->translate('PLUGIN_LOGIN.RESET_PASSWORD_RESET'), 'info');
                    $this->setRedirect($this->grav['config']->get('plugins.login.route', '/'));

                    return true;
                }
            }

            $messages->add($language->translate('PLUGIN_LOGIN.RESET_INVALID_LINK'), 'error');
            $this->grav->redirect($this->grav['config']->get('plugins.login.route_forgot'));

            return true;

        }

        $user = $this->grav['uri']->param('user');
        $token = $this->grav['uri']->param('token');

        if (!$user || !$token) {
            $messages->add($language->translate('PLUGIN_LOGIN.RESET_INVALID_LINK'), 'error');
            $this->grav->redirect($this->grav['config']->get('plugins.login.route_forgot'));

            return true;
        }

        return true;
    }

    /**
     * Authenticate user.
     *
     * @param  array $form Form fields.
     *
     * @return bool
     */
    protected function authenticate($form)
    {
        // Remove login nonce.
        $form = array_diff_key($form, ['login-form-nonce' => true]);

        return $this->login->login($form, ['remember_me' => true])->authenticated;
    }

    /**
     * Redirects an action
     */
    public function redirect()
    {
        if ($this->redirect) {
            $this->grav->redirect($this->redirect, $this->redirectCode);
        }
    }

    /**
     * Set redirect.
     *
     * @param     $path
     * @param int $code
     */
    public function setRedirect($path, $code = 303)
    {
        $this->redirect = $path;
        $this->redirectCode = $code;
    }

    /**
     * Add message into the session queue.
     *
     * @param string $msg
     * @param string $type
     */
    public function setMessage($msg, $type = 'info')
    {
        /** @var Message $messages */
        $messages = $this->grav['messages'];
        $messages->add($msg, $type);
    }

    /**
     * Gets and sets the RememberMe class
     *
     * @param  mixed $var A rememberMe instance to set
     *
     * @return RememberMe\RememberMe Returns the current rememberMe instance
     */
    public function rememberMe($var = null)
    {
        $this->rememberMe = $this->login->rememberMe($var);

        return $this->rememberMe;
    }

    /**
     * Prepare and return POST data.
     *
     * @param array $post
     *
     * @return array
     */
    protected function &getPost(array $post)
    {
        unset($post[$this->prefix]);

        // Decode JSON encoded fields and merge them to data.
        if (isset($post['_json'])) {
            $post = array_merge_recursive($post, $this->jsonDecode($post['_json']));
            unset($post['_json']);
        }

        return $post;
    }

    /**
     * Recursively JSON decode data.
     *
     * @param  array $data
     *
     * @return array
     */
    protected function jsonDecode(array $data)
    {
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = $this->jsonDecode($value);
            } else {
                $value = json_decode($value, true);
            }
        }

        return $data;
    }


    /**
     * Check if user may use password reset functionality.
     *
     * @param  User $user
     * @param $field
     * @param $count
     * @param $interval
     * @return bool
     */
    protected function isUserRateLimited(User $user, $field, $count, $interval)
    {
        return $this->login->isUserRateLimited($user, $field, $count, $interval);
    }

    /**
     * Reset the rate limit counter
     *
     * @param User $user
     * @param $field
     */
    protected function resetRateLimit(User $user, $field)
    {
        $this->login->resetRateLimit($user, $field);
    }
}
