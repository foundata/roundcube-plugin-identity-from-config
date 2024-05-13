<?php

/**
 * Maintains (additional) user's identities from static settings of this
 * plugin's config file.
 *
 * @license SPDX-License-Identifier: GPL-3.0-or-later
 * @copyright SPDX-FileCopyrightText: foundata GmbH <https://foundata.com>
 */
class identity_from_config extends rcube_plugin
{
    public $task = 'login';

    private $rc;
    private $ldap;


    /**
     * Plugin initialization. API hooks binding.
     */
    public function init()
    {
        $this->rc = rcmail::get_instance();

        // Triggered after a user successfully logged in
        // https://github.com/roundcube/roundcubemail/wiki/Plugin-Hooks#login_after
        // This plugin is using it to update / edit existing identities
        $this->add_hook('login_after', [$this, 'login_after']);
    }


    /**
     * 'login_after' hook handler, used to create or update the user identities
     */
    public function login_after($args)
    {
        $this->load_config('config.inc.php.dist'); // load the plugin's distribution config file as default
        $this->load_config(); // merge with local configuration file (which can overwrite any settings)
        if ($this->ldap) {
            return $args;
        }
        $debug_plugin = (bool) $this->rc->config->get('identity_from_config_debug');

        // get user's identity data from config and prepare it for further processing
        $user_data = [
            'username' => $this->rc->user->data['username'],
            'identity_from_config_list' => [], // copied identities from config file, matching for this user
            'managed_identity_ids' => [] // list of IDs of identities managed by this plugin
        ];
        $identities_from_config = (array) $this->rc->config->get('identity_from_config_identities');
        foreach ($identities_from_config as $email => $identity_from_config) {

            // check plugin config
            if (empty($identity_from_config) ||
                (!array_key_exists('users', $identity_from_config) || empty($identity_from_config['users']) || !is_array($identity_from_config['users'])) ||
                (!array_key_exists('fallback_values', $identity_from_config) || !is_array($identity_from_config['fallback_values'])) ||
                !array_key_exists('signature_plaintext', $identity_from_config) ||
                !array_key_exists('signature_html', $identity_from_config)) {
                if ($debug_plugin) {
                    rcube::write_log('identity_from_config',
                        'The plugin config seems to be invalid, please check the defined '
                        . 'identities in $config[\'identity_from_config_identities\'] for '
                        . 'invalid or missing but mandatory array keys.') ;
                }
                return false;
            }

            // check if this identity has to be added for the current user
            $roundcube_username = mb_strtolower($user_data['username'], RCUBE_CHARSET); // case-insensitive search
            foreach($identity_from_config['users'] as $identity_users_username => $user_config_dataset) {

                $search_username = mb_strtolower($identity_users_username, RCUBE_CHARSET); // case-insensitive search
                if ($roundcube_username === $search_username) {
                    if ($debug_plugin) {
                        rcube::write_log('identity_from_config',
                            'Found an identity record for user \'' . $this->rc->user->data['username'] . '\' '
                            . 'based on a search for \'' . $roundcube_username . '\': ' . print_r($identity_from_config, true));
                    }
                    if (strpos($email, '@') === false) {
                        if ($debug_plugin) {
                            rcube::write_log('identity_from_config',
                                'Excluded \''. $email . '\' as it is an invalid email address.');
                        }
                        continue;
                    }

                    // Add additional data to this identity from config to make some actions
                    // easier while keeping the configuration simple:
                    // - email (as it is only stored in the indentity's array key yet)
                    // - email as fallback value for placeholders. So it gets used in templates
                    //   if there is no user-specific value in $user_config_dataset.
                    // - the whole set of user specific data (so we do not have to search again
                    //   in all subarrays)
                    $identity_from_config['email'] = $email;
                    if (!array_key_exists('email', $identity_from_config['fallback_values']) || empty($identity_from_config['fallback_values']['email'])) {
                        $identity_from_config['fallback_values']['email'] = $email;
                    }
                    $identity_from_config['user_config_dataset'] = $user_config_dataset;


                    // copy the identity from config file to the list to be processed for the
                    // current user
                    $user_data['identity_from_config_list'][] = $identity_from_config;
                    break;
                }

            }
        }
        if (empty($user_data['identity_from_config_list'])) {
            return $args;
        }

        // get config and other data needed for further processing
        $update_signatures = (bool) $this->rc->config->get('identity_from_config_update_signatures');
        $use_html_signature = (bool) $this->rc->config->get('identity_from_config_use_html_signature');
        $wash_html_signature = (bool) $this->rc->config->get('identity_from_config_wash_html_signature');
        $identities_existing = $this->rc->user->list_emails(); // list of all user emails (from identities), array with identity_id, name and email address

        // maintain an identity for each of the user's determined email addresses
        foreach ((array) $user_data['identity_from_config_list'] as $identity_from_config) {
            // misc inits
            $hook_to_use = 'identity_create';
            $identity_id = 0; // often called 'iid' in other parts of RC sources
            foreach ($identities_existing as $identity_existing) {
                // case-insensitive search to update an existing identity, even if
                // there are differences in capitalization.
                if (self::email_in_array($identity_existing['email'], [ $identity_from_config['email'] ])) {
                    $hook_to_use = 'identity_update';
                    $identity_id = $identity_existing['identity_id'];
                    break;
                }
            }

            // see https://github.com/roundcube/roundcubemail/blob/master/program/actions/settings/identity_save.php for available keys
            $identity_record = [
                'user_id' => $this->rc->user->ID,
                'standard' => ((array_key_exists('is_standard', $identity_from_config) && !empty($identity_from_config['is_standard'])) ? 1 : 0), // 1: use the identity as default (there can only be one)
                'name' => ((array_key_exists('name', $identity_from_config['user_config_dataset']) && !empty($identity_from_config['user_config_dataset']['name'])) ? $identity_from_config['user_config_dataset']['name'] : $user_data['username']),
                'email' => $identity_from_config['email'],
                'organization' => (array_key_exists('organization', $identity_from_config) ? $identity_from_config['organization'] : ''),
                'reply-to' => (array_key_exists('reply-to', $identity_from_config) ? $identity_from_config['reply-to'] : ''),
                'bcc' => (array_key_exists('bcc', $identity_from_config) ? $identity_from_config['bcc'] : ''),
            ];

            if ($update_signatures) {
                // copy signature template
                if ($use_html_signature) {
                    $signature = (string) $identity_from_config['signature_html'];
                } else {
                    $signature = (string) $identity_from_config['signature_plaintext'];
                }

                // add signature to identity record, replace placeholders in the signature template with
                // the values from the user's identity dataset or the identities ['fallback_values'] array:
                // - %foo%: raw value of field 'foo'
                // - %foo_html%: HTML entities encoded value of field 'foo'
                // - %foo_url%: URL encoded value of field 'foo'. Additional optimizations are
                //   applied for the fields 'email' (usage of Punycode for email domains),
                //   'phone' and 'fax' (stripping of chars not compatible with tel:// URLs)
                $fieldmap = array_unique(array_merge(array_keys($identity_from_config['fallback_values']), array_keys($identity_from_config['user_config_dataset'])));
                foreach ($fieldmap as $placeholder) {
                    $replace_raw = '';
                    if (array_key_exists($placeholder, $identity_from_config['user_config_dataset']) && ((string) $identity_from_config['user_config_dataset'][$placeholder] !== '')) {
                        $replace_raw = (string) $identity_from_config['user_config_dataset'][$placeholder];
                    } elseif (array_key_exists($placeholder, $identity_from_config['fallback_values']) && ((string) $identity_from_config['fallback_values'][$placeholder] !== '')) {
                        $replace_raw = (string) $identity_from_config['fallback_values'][$placeholder];
                    } elseif (array_key_exists($placeholder, $identity_record) && ((string) $identity_record[$placeholder] !== '')) {
                        $replace_raw = (string) $identity_record[$placeholder];
                    } else {
                        continue;
                    }

                    $replace_html = '';
                    $replace_html = htmlspecialchars($replace_raw, \ENT_NOQUOTES, RCUBE_CHARSET);

                    $replace_url = '';
                    if ($placeholder === 'phone' || $placeholder === 'fax') {
                        // strip some chars for "tel://" URL usage
                        $replace_url = urlencode(preg_replace('/[^+0-9]+/', '', $replace_raw));
                    } elseif ($placeholder === 'email') {
                        // use Punycode/ACE for "mailto://" URL usage
                        $replace_url = urlencode(rcube_utils::idn_to_ascii($replace_raw));
                    } else {
                        $replace_url = urlencode($replace_raw);
                    }

                    $signature = str_replace([ '%'. $placeholder . '%',
                                               '%'. $placeholder . '_html%',
                                               '%'. $placeholder . '_url%' ],
                                             [ $replace_raw,
                                               $replace_html,
                                               $replace_url ], $signature);
                }

                $identity_record['html_signature'] = ($use_html_signature) ? 1 : 0;
                $identity_record['signature'] = ($use_html_signature && $wash_html_signature) ? rcmail_action_settings_index::wash_html($signature) : $signature; // XSS protection
            }

            $plugin = $this->rc->plugins->exec_hook($hook_to_use, [
                'id' => $identity_id,
                'record' => $identity_record,
            ]);

            if (!$plugin['abort'] && !empty($plugin['record']['email'])) {
                if ($identity_id === 0) {
                    $identity_id = $this->rc->user->insert_identity($plugin['record']);
                } else {
                    $this->rc->user->update_identity($identity_id, $plugin['record']);
                }
            }

            if (empty($identity_id)) {
                if ($debug_plugin) {
                    rcube::write_log('identity_from_config',
                        'The identity for user \'' . $this->rc->user->data['username'] . '\' '
                        . 'could not be saved' . (!empty($plugin['message']) ? ' ( ' . $plugin['message'] . ' ): ' : ': ')
                        . print_r($identity_record, true));
                }
                continue;
            }

            // Store the ID of the identity as managed. Any optionally needed cleanup
            // action gets a lot easier if there is a list of identities known to be
            // managed for the current user by this plugin.
            $user_data['managed_identity_ids'][] = $identity_id;
        }

        // delete identities which are not managed by this plugin
        $delete_unmanaged = (bool) $this->rc->config->get('identity_from_config_delete_unmanaged');
        $exclude_delete_unmanaged_regex = (string) $this->rc->config->get('identity_from_config_exclude_delete_unmanaged_regex');
        if ($delete_unmanaged) {
            $identity_existing_count = count($identities_existing);
            foreach ($identities_existing as $identity_existing) {

                if ($identity_existing_count > 1 && !(in_array($identity_existing['identity_id'], $user_data['managed_identity_ids']))) {

                    if (!empty($exclude_delete_unmanaged_regex) && preg_match($exclude_delete_unmanaged_regex, $identity_existing['email'])) {
                        if ($debug_plugin) {
                            rcube::write_log('identity_from_config',
                                'Excluded identity ' . $identity_existing['identity_id'] . ' of user '
                                . $this->rc->user->data['username'] . ' from automatic deletion even '
                                . 'though it is not managed by this plugin; it\'s email address '
                                . $identity_existing['email'] . ' is matching "' . $exclude_delete_unmanaged_regex
                                . '" (identity_from_config_exclude_delete_unmanaged_regex).');
                        }
                        continue;
                    }

                    if ($debug_plugin) {
                        rcube::write_log('identity_from_config',
                            'Deleting identity '. $identity_existing['identity_id'] .' with email address '
                            . $identity_existing['email'] . ' because it is not managed by this plugin.');
                    }

                    if (!($this->rc->user->delete_identity($identity_existing['identity_id'])) && $debug_plugin) {
                        rcube::write_log('identity_from_config',
                            'Could note delete identity '. $identity_existing['identity_id']
                            . ' with email address '.$identity_existing['email']);
                    }
                    $identity_existing_count--;

                }

            }
        }

        return $args;
    }


    /**
     * Search for an email address in an array of email addresses. The search
     * will ignores differences in capitalization or Punycode/ACE.
     *
     * RFC 5321 (Simple Mail Transfer Protocol) section 2.3.11 leaves it up
     * to the host if the "local-part" in "local-part@domain" is case-
     * insensitively. De-facto, it gets handled case-insensitive by most
     * systems out there and users are expecting that Foo@example.com ==
     * foo@example.com quite often. This function also acts like this.
     *
     * @param string $needle Value to seek.
     * @param array $haystack Array to seek in.
     * @return bool
     */
    public static function email_in_array($needle, $haystack)
    {
        $haystack_new = [];
        foreach($haystack as $key => $value) {
            $haystack_new[$key] = mb_strtolower(rcube_utils::idn_to_utf8(trim($value)), 'UTF-8');
        }
        return in_array(mb_strtolower(rcube_utils::idn_to_utf8(trim($needle)), 'UTF-8'), $haystack_new);
    }
}
