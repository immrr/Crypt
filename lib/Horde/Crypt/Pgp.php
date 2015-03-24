<?php
/**
 * Copyright 2002-2015 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category  Horde
 * @copyright 2002-2015 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Crypt
 */

/**
 * A framework for Horde applications to interact with the GNU Privacy Guard
 * program ("GnuPG").  GnuPG implements the OpenPGP standard (RFC 4880).
 *
 * GnuPG Website: ({@link http://www.gnupg.org/})
 *
 * @author    Michael Slusarz <slusarz@horde.org>
 * @author    Jan Schneider <jan@horde.org>
 * @category  Horde
 * @copyright 2002-2015 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Crypt
 */
class Horde_Crypt_Pgp extends Horde_Crypt
{
    /**
     * The list of PGP hash algorithms (from RFC 3156).
     *
     * @var array
     */
    protected $_hashAlg = array(
        1 => 'pgp-md5',
        2 => 'pgp-sha1',
        3 => 'pgp-ripemd160',
        5 => 'pgp-md2',
        6 => 'pgp-tiger192',
        7 => 'pgp-haval-5-160',
        8 => 'pgp-sha256',
        9 => 'pgp-sha384',
        10 => 'pgp-sha512',
        11 => 'pgp-sha224',
    );

    /**
     * GnuPG program location/common options.
     *
     * @var array
     */
    protected $_gnupg;

    /**
     * Filename of the temporary public keyring.
     *
     * @var string
     */
    protected $_publicKeyring;

    /**
     * Filename of the temporary private keyring.
     *
     * @var string
     */
    protected $_privateKeyring;

    /**
     * Configuration parameters.
     *
     * @var array
     */
    protected $_params = array();

    /**
     * Constructor.
     *
     * @param array $params  Configuration parameters:
     *   - program: (string) [REQUIRED] The path to the GnuPG binary.
     *   - proxy_host: (string) Proxy host. (@deprecated)
     *   - proxy_port: (integer) Proxy port. (@deprecated)
     */
    public function __construct($params = array())
    {
        parent::__construct($params);

        if (empty($params['program'])) {
            throw new InvalidArgumentException(
                'The location of the GnuPG binary must be given to the Horde_Crypt_Pgp:: class.'
            );
        }

        /* Store the location of GnuPG and set common options. */
        $this->_gnupg = array(
            $params['program'],
            '--no-tty',
            '--no-secmem-warning',
            '--no-options',
            '--no-default-keyring',
            '--yes',
            '--homedir ' . $this->_tempdir
        );

        $this->_params = $params;
    }

    /**
     * Generates a personal Public/Private keypair combination.
     *
     * @param string $realname     The name to use for the key.
     * @param string $email        The email to use for the key.
     * @param string $passphrase   The passphrase to use for the key.
     * @param string $comment      The comment to use for the key.
     * @param integer $keylength   The keylength to use for the key.
     * @param integer $expire      The expiration date (UNIX timestamp). No
     *                             expiration if empty.
     * @param string $key_type     Key type (@since 2.2.0).
     * @param string $subkey_type  Subkey type (@since 2.2.0).
     *
     * @return array  An array consisting of the following keys/values:
     *   - public: (string) Public key.
     *   - private: (string) Private key.
     *
     * @throws Horde_Crypt_Exception
     */
    public function generateKey($realname, $email, $passphrase, $comment = '',
                                $keylength = 1024, $expire = null,
                                $key_type = 'RSA', $subkey_type = 'RSA')
    {
        /* Create temp files to hold the generated keys. */
        $pub_file = $this->_createTempFile('horde-pgp');
        $secret_file = $this->_createTempFile('horde-pgp');

        $expire = empty($expire)
            ? 0
            : date('Y-m-d', $expire);

        /* Create the config file necessary for GnuPG to run in batch mode. */
        /* TODO: Sanitize input, More user customizable? */
        $input = array(
            '%pubring ' . $pub_file,
            '%secring ' . $secret_file,
            'Key-Type: ' . $key_type,
            'Key-Length: ' . $keylength,
            'Subkey-Type: ' . $subkey_type,
            'Subkey-Length: ' . $keylength,
            'Name-Real: ' . $realname,
            'Name-Email: ' . $email,
            'Expire-Date: ' . $expire,
            'Passphrase: ' . $passphrase,
            'Preferences: AES256 AES192 AES CAST5 3DES SHA256 SHA512 SHA384 SHA224 SHA1 ZLIB BZIP2 ZIP Uncompressed'
        );
        if (!empty($comment)) {
            $input[] = 'Name-Comment: ' . $comment;
        }
        $input[] = '%commit';

        /* Run through gpg binary. */
        $cmdline = array(
            '--gen-key',
            '--batch',
            '--armor'
        );

        $result = $this->_callGpg($cmdline, 'w', $input, true, true);

        /* Get the keys from the temp files. */
        $public_key = file_get_contents($pub_file);
        $secret_key = file_get_contents($secret_file);

        /* If either key is empty, something went wrong. */
        if (empty($public_key) || empty($secret_key)) {
            $msg = Horde_Crypt_Translation::t("Public/Private keypair not generated successfully.");
            if (!empty($result->stderr)) {
                $msg .= ' ' . Horde_Crypt_Translation::t("Returned error message:") . ' ' . $result->stderr;
            }
            throw new Horde_Crypt_Exception($msg);
        }

        return array(
            'public' => $public_key,
            'private' => $secret_key
        );
    }

    /**
     * Returns information on a PGP data block.
     *
     * @param string $pgpdata  The PGP data block.
     *
     * @return array  An array with information on the PGP data block. If an
     *                element is not present in the data block, it will
     *                likewise not be set in the array.
     * <pre>
     * Array Format:
     * -------------
     * [public_key]/[secret_key] => Array
     *   (
     *     [created] => Key creation - UNIX timestamp
     *     [expires] => Key expiration - UNIX timestamp (0 = never expires)
     *     [size]    => Size of the key in bits
     *   )
     *
     * [keyid] => Key ID of the PGP data (if available)
     *            16-bit hex value
     *
     * [signature] => Array (
     *     [id{n}/'_SIGNATURE'] => Array (
     *         [name]        => Full Name
     *         [comment]     => Comment
     *         [email]       => E-mail Address
     *         [keyid]       => 16-bit hex value
     *         [created]     => Signature creation - UNIX timestamp
     *         [expires]     => Signature expiration - UNIX timestamp
     *         [micalg]      => The hash used to create the signature
     *         [sig_{hex}]   => Array [details of a sig verifying the ID] (
     *             [created]     => Signature creation - UNIX timestamp
     *             [expires]     => Signature expiration - UNIX timestamp
     *             [keyid]       => 16-bit hex value
     *             [micalg]      => The hash used to create the signature
     *         )
     *     )
     * )
     * </pre>
     *
     * Each user ID will be stored in the array 'signature' and have data
     * associated with it, including an array for information on each
     * signature that has signed that UID. Signatures not associated with a
     * UID (e.g. revocation signatures and sub keys) will be stored under the
     * special keyword '_SIGNATURE'.
     *
     * @throws Horde_Crypt_Exception
     */
    public function pgpPacketInformation($pgpdata)
    {
        $header = $keyid = null;
        $input = $this->_createTempFile('horde-pgp');
        $sig_id = $uid_idx = 0;
        $out = array();

        $this2 = $this;
        $_pgpPacketInformationKeyId = function ($input) use ($this2) {
            $data = $this2->_callGpg(array('--with-colons', $input), 'r');
            return preg_match('/(sec|pub):.*:.*:.*:([A-F0-9]{16}):/', $data->stdout, $matches)
                ? $matches[2]
                : null;
        };
        $_pgpPacketInformationHelper = function ($a) {
            return chr(hexdec($a[1]));
        };

        /* Store message in temporary file. */
        file_put_contents($input, $pgpdata);

        $cmdline = array(
            '--list-packets',
            $input
        );
        $result = $this->_callGpg($cmdline, 'r', null, false, false, true);

        foreach (explode("\n", $result->stdout) as $line) {
            /* Headers are prefaced with a ':' as the first character on the
             * line. */
            if (strpos($line, ':') === 0) {
                $lowerLine = Horde_String::lower($line);

                if (strpos($lowerLine, ':public key packet:') !== false) {
                    $header = 'public_key';
                } elseif (strpos($lowerLine, ':secret key packet:') !== false) {
                    $header = 'secret_key';
                } elseif (strpos($lowerLine, ':user id packet:') !== false) {
                    $uid_idx++;
                    $line = preg_replace_callback('/\\\\x([0-9a-f]{2})/', $_pgpPacketInformationHelper, $line);
                    if (preg_match("/\"([^\<]+)\<([^\>]+)\>\"/", $line, $matches)) {
                        $header = 'id' . $uid_idx;
                        if (preg_match('/([^\(]+)\((.+)\)$/', trim($matches[1]), $comment_matches)) {
                            $out['signature'][$header]['name'] = trim($comment_matches[1]);
                            $out['signature'][$header]['comment'] = $comment_matches[2];
                        } else {
                            $out['signature'][$header]['name'] = trim($matches[1]);
                            $out['signature'][$header]['comment'] = '';
                        }
                        $out['signature'][$header]['email'] = $matches[2];
                        if (is_null($keyid)) {
                            $keyid = $_pgpPacketInformationKeyId($input);
                        }
                        $out['signature'][$header]['keyid'] = $keyid;
                    }
                } elseif (strpos($lowerLine, ':signature packet:') !== false) {
                    if (empty($header) || empty($uid_idx)) {
                        $header = '_SIGNATURE';
                    }
                    if (preg_match("/keyid\s+([0-9A-F]+)/i", $line, $matches)) {
                        $sig_id = $matches[1];
                        $out['signature'][$header]['sig_' . $sig_id]['keyid'] = $matches[1];
                        $out['keyid'] = $matches[1];
                    }
                } elseif (strpos($lowerLine, ':literal data packet:') !== false) {
                    $header = 'literal';
                } elseif (strpos($lowerLine, ':encrypted data packet:') !== false) {
                    $header = 'encrypted';
                } else {
                    $header = null;
                }
            } else {
                if ($header == 'secret_key' || $header == 'public_key') {
                    if (preg_match("/created\s+(\d+),\s+expires\s+(\d+)/i", $line, $matches)) {
                        $out[$header]['created'] = $matches[1];
                        $out[$header]['expires'] = $matches[2];
                    } elseif (preg_match("/\s+[sp]key\[0\]:\s+\[(\d+)/i", $line, $matches)) {
                        $out[$header]['size'] = $matches[1];
                    } elseif (preg_match("/\s+keyid:\s+([0-9A-F]+)/i", $line, $matches)) {
                        $keyid = $matches[1];
                    }
                } elseif ($header == 'literal' || $header == 'encrypted') {
                    $out[$header] = true;
                } elseif ($header) {
                    if (preg_match("/version\s+\d+,\s+created\s+(\d+)/i", $line, $matches)) {
                        $out['signature'][$header]['sig_' . $sig_id]['created'] = $matches[1];
                    } elseif (isset($out['signature'][$header]['sig_' . $sig_id]['created']) &&
                              preg_match('/expires after (\d+y\d+d\d+h\d+m)\)$/', $line, $matches)) {
                        $expires = $matches[1];
                        preg_match('/^(\d+)y(\d+)d(\d+)h(\d+)m$/', $expires, $matches);
                        list(, $years, $days, $hours, $minutes) = $matches;
                        $out['signature'][$header]['sig_' . $sig_id]['expires'] =
                            strtotime('+ ' . $years . ' years + ' . $days . ' days + ' . $hours . ' hours + ' . $minutes . ' minutes', $out['signature'][$header]['sig_' . $sig_id]['created']);
                    } elseif (preg_match("/digest algo\s+(\d{1})/", $line, $matches)) {
                        $micalg = $this->_hashAlg[$matches[1]];
                        $out['signature'][$header]['sig_' . $sig_id]['micalg'] = $micalg;
                        if ($header == '_SIGNATURE') {
                            /* Likely a signature block, not a key. */
                            $out['signature']['_SIGNATURE']['micalg'] = $micalg;
                        }

                        if (is_null($keyid)) {
                            $keyid = $_pgpPacketInformationKeyId($input);
                        }

                        if ($sig_id == $keyid) {
                            /* Self signing signature - we can assume
                             * the micalg value from this signature is
                             * that for the key */
                            $out['signature']['_SIGNATURE']['micalg'] = $micalg;
                            $out['signature'][$header]['micalg'] = $micalg;
                        }
                    }
                }
            }
        }

        if (is_null($keyid)) {
            $keyid = $_pgpPacketInformationKeyId($input);
        }

        $keyid && $out['keyid'] = $keyid;

        return $out;
    }

    /**
     * Returns human readable information on a PGP key.
     *
     * @param string $pgpdata  The PGP data block.
     *
     * @return string  Tabular information on the PGP key.
     * @throws Horde_Crypt_Exception
     */
    public function pgpPrettyKey($pgpdata)
    {
        $msg = '';
        $packet_info = $this->pgpPacketInformation($pgpdata);
        $fingerprints = $this->getFingerprintsFromKey($pgpdata);

        $_pgpPrettyKeyFormatter = function (&$s, $k, $m) {
            $s .= ':' . str_repeat(' ', $m - Horde_String::length($s));
        };

        if (!empty($packet_info['signature'])) {
            /* Making the property names the same width for all
             * localizations .*/
            $leftrow = array(
                Horde_Crypt_Translation::t("Name"),
                Horde_Crypt_Translation::t("Key Type"),
                Horde_Crypt_Translation::t("Key Creation"),
                Horde_Crypt_Translation::t("Expiration Date"),
                Horde_Crypt_Translation::t("Key Length"),
                Horde_Crypt_Translation::t("Comment"),
                Horde_Crypt_Translation::t("E-Mail"),
                Horde_Crypt_Translation::t("Hash-Algorithm"),
                Horde_Crypt_Translation::t("Key ID"),
                Horde_Crypt_Translation::t("Key Fingerprint")
            );
            $leftwidth = array_map('strlen', $leftrow);
            $maxwidth  = max($leftwidth) + 2;
            array_walk($leftrow, $_pgpPrettyKeyFormatter, $maxwidth);

            foreach ($packet_info['signature'] as $uid_idx => $val) {
                if ($uid_idx == '_SIGNATURE') {
                    continue;
                }
                $key_info = $this->pgpPacketSignatureByUidIndex($pgpdata, $uid_idx);

                $keyid = empty($key_info['keyid'])
                    ? null
                    : $this->getKeyIDString($key_info['keyid']);
                $fingerprint = isset($fingerprints[$keyid])
                    ? $fingerprints[$keyid]
                    : null;
                $sig_key = 'sig_' . $key_info['keyid'];

                $msg .= $leftrow[0] . (isset($key_info['name']) ? stripcslashes($key_info['name']) : '') . "\n"
                    . $leftrow[1] . (($key_info['key_type'] == 'public_key') ? Horde_Crypt_Translation::t("Public Key") : Horde_Crypt_Translation::t("Private Key")) . "\n"
                    . $leftrow[2] . strftime("%D", $val[$sig_key]['created']) . "\n"
                    . $leftrow[3] . (empty($val[$sig_key]['expires']) ? '[' . Horde_Crypt_Translation::t("Never") . ']' : strftime("%D", $val[$sig_key]['expires'])) . "\n"
                    . $leftrow[4] . $key_info['key_size'] . " Bytes\n"
                    . $leftrow[5] . (empty($key_info['comment']) ? '[' . Horde_Crypt_Translation::t("None") . ']' : $key_info['comment']) . "\n"
                    . $leftrow[6] . (empty($key_info['email']) ? '[' . Horde_Crypt_Translation::t("None") . ']' : $key_info['email']) . "\n"
                    . $leftrow[7] . (empty($key_info['micalg']) ? '[' . Horde_Crypt_Translation::t("Unknown") . ']' : $key_info['micalg']) . "\n"
                    . $leftrow[8] . (empty($keyid) ? '[' . Horde_Crypt_Translation::t("Unknown") . ']' : $keyid) . "\n"
                    . $leftrow[9] . (empty($fingerprint) ? '[' . Horde_Crypt_Translation::t("Unknown") . ']' : $fingerprint) . "\n\n";
            }
        }

        return $msg;
    }

    /**
     * TODO
     *
     * @since 2.4.0
     */
    public function getKeyIDString($keyid)
    {
        /* Get the 8 character key ID string. */
        if (strpos($keyid, '0x') === 0) {
            $keyid = substr($keyid, 2);
        }
        if (strlen($keyid) > 8) {
            $keyid = substr($keyid, -8);
        }
        return '0x' . $keyid;
    }

    /**
     * Returns only information on the first ID that matches the email address
     * input.
     *
     * @param string $pgpdata  The PGP data block.
     * @param string $email    An e-mail address.
     *
     * @return array  An array with information on the PGP data block. If an
     *                element is not present in the data block, it will
     *                likewise not be set in the array.
     * <pre>
     * Array Fields:
     * -------------
     * key_created  =>  Key creation - UNIX timestamp
     * key_expires  =>  Key expiration - UNIX timestamp (0 = never expires)
     * key_size     =>  Size of the key in bits
     * key_type     =>  The key type (public_key or secret_key)
     * name         =>  Full Name
     * comment      =>  Comment
     * email        =>  E-mail Address
     * keyid        =>  16-bit hex value
     * created      =>  Signature creation - UNIX timestamp
     * micalg       =>  The hash used to create the signature
     * </pre>
     * @throws Horde_Crypt_Exception
     */
    public function pgpPacketSignature($pgpdata, $email)
    {
        $data = $this->pgpPacketInformation($pgpdata);
        $out = array();

        /* Check that [signature] key exists. */
        if (!isset($data['signature'])) {
            return $out;
        }

        /* Store the signature information now. */
        if (($email == '_SIGNATURE') &&
            isset($data['signature']['_SIGNATURE'])) {
            foreach ($data['signature'][$email] as $key => $value) {
                $out[$key] = $value;
            }
        } else {
            $uid_idx = 1;

            while (isset($data['signature']['id' . $uid_idx])) {
                if ($data['signature']['id' . $uid_idx]['email'] == $email) {
                    foreach ($data['signature']['id' . $uid_idx] as $key => $val) {
                        $out[$key] = $val;
                    }
                    break;
                }
                ++$uid_idx;
            }
        }

        return $this->_pgpPacketSignature($data, $out);
    }

    /**
     * Returns information on a PGP signature embedded in PGP data.  Similar
     * to pgpPacketSignature(), but returns information by unique User ID
     * Index (format id{n} where n is an integer of 1 or greater).
     *
     * @see pgpPacketSignature()
     *
     * @param string $pgpdata  See pgpPacketSignature().
     * @param string $uid_idx  The UID index.
     *
     * @return array  See pgpPacketSignature().
     * @throws Horde_Crypt_Exception
     */
    public function pgpPacketSignatureByUidIndex($pgpdata, $uid_idx)
    {
        $data = $this->pgpPacketInformation($pgpdata);

        return isset($data['signature'][$uid_idx])
            ? $this->_pgpPacketSignature($data, $data['signature'][$uid_idx])
            : array();
    }

    /**
     * Adds some data to the pgpPacketSignature*() function array.
     *
     * @see pgpPacketSignature().
     *
     * @param array $data      See pgpPacketSignature().
     * @param array $retarray  The return array.
     *
     * @return array  The return array.
     */
    protected function _pgpPacketSignature($data, $retarray)
    {
        /* If empty, return now. */
        if (empty($retarray)) {
            return $retarray;
        }

        $key_type = null;

        /* Store any public/private key information. */
        if (isset($data['public_key'])) {
            $key_type = 'public_key';
        } elseif (isset($data['secret_key'])) {
            $key_type = 'secret_key';
        }

        if ($key_type) {
            $retarray['key_type'] = $key_type;
            if (isset($data[$key_type]['created'])) {
                $retarray['key_created'] = $data[$key_type]['created'];
            }
            if (isset($data[$key_type]['expires'])) {
                $retarray['key_expires'] = $data[$key_type]['expires'];
            }
            if (isset($data[$key_type]['size'])) {
                $retarray['key_size'] = $data[$key_type]['size'];
            }
        }

        return $retarray;
    }

    /**
     * Returns the key ID of the key used to sign a block of PGP data.
     *
     * @param string $text  The PGP signed text block.
     *
     * @return string  The key ID of the key used to sign $text.
     * @throws Horde_Crypt_Exception
     */
    public function getSignersKeyID($text)
    {
        $input = $this->_createTempFile('horde-pgp');
        file_put_contents($input, $text);

        $result = $this->_callGpg(
            array(
                '--verify',
                $input
            ),
            'r',
            null,
            true,
            true,
            true
        );

        return preg_match('/gpg:\sSignature\smade.*ID\s+([A-F0-9]{8})\s+/', $result->stderr, $matches)
            ? $matches[1]
            : null;
    }

    /**
     * Verify a passphrase for a given public/private keypair.
     *
     * @param string $public_key   The user's PGP public key.
     * @param string $private_key  The user's PGP private key.
     * @param string $passphrase   The user's passphrase.
     *
     * @return boolean  Returns true on valid passphrase, false on invalid
     *                  passphrase.
     * @throws Horde_Crypt_Exception
     */
    public function verifyPassphrase($public_key, $private_key, $passphrase)
    {
        /* Get e-mail address of public key. */
        $key_info = $this->pgpPacketInformation($public_key);
        if (!isset($key_info['signature']['id1']['email'])) {
            throw new Horde_Crypt_Exception(
                Horde_Crypt_Translation::t("Could not determine the recipient's e-mail address.")
            );
        }

        /* Encrypt a test message. */
        try {
            $result = $this->encrypt(
                'Test',
                array(
                    'type' => 'message',
                    'pubkey' => $public_key,
                    'recips' => array(
                        $key_info['signature']['id1']['email'] => $public_key
                    )
                )
            );
        } catch (Horde_Crypt_Exception $e) {
            return false;
        }

        /* Try to decrypt the message. */
        try {
            $this->decrypt(
                $result,
                array(
                    'type' => 'message',
                    'pubkey' => $public_key,
                    'privkey' => $private_key,
                    'passphrase' => $passphrase
                )
            );
        } catch (Horde_Crypt_Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Sends a PGP public key to a public keyserver.
     *
     * @param string $pubkey  The PGP public key
     * @param string $server  The keyserver to use.
     * @param float $timeout  The keyserver timeout.
     *
     * @throws Horde_Crypt_Exception
     */
    public function putPublicKeyserver($pubkey,
                                       $server = self::KEYSERVER_PUBLIC,
                                       $timeout = self::KEYSERVER_TIMEOUT)
    {
        return $this->_getKeyserverOb($server)->put($pubkey);
    }

    /**
     * Returns the first matching key ID for an email address from a
     * public keyserver.
     *
     * @param string $address  The email address of the PGP key.
     * @param string $server   The keyserver to use.
     * @param float $timeout   The keyserver timeout.
     *
     * @return string  The PGP key ID.
     * @throws Horde_Crypt_Exception
     */
    public function getKeyID($address, $server = self::KEYSERVER_PUBLIC,
                             $timeout = self::KEYSERVER_TIMEOUT)
    {
        return $this->_getKeyserverOb($server)->getKeyId($address);
    }

    /**
     * Get the fingerprints from a key block.
     *
     * @param string $pgpdata  The PGP data block.
     *
     * @return array  The fingerprints in $pgpdata indexed by key id.
     * @throws Horde_Crypt_Exception
     */
    public function getFingerprintsFromKey($pgpdata)
    {
        $fingerprints = array();

        /* Store the key in a temporary keyring. */
        $keyring = $this->_putInKeyring($pgpdata);

        $result = $this->_callGpg(
            array(
                '--fingerprint',
                $keyring,
            ),
            'r',
            null,
            true,
            false,
            true
        );
        if (!$result || !$result->stdout) {
            return $fingerprints;
        }

        /* Parse fingerprints and key ids from output. */
        $lines = explode("\n", $result->stdout);
        $keyid = null;
        foreach ($lines as $line) {
            if (preg_match('/pub\s+\w+\/(\w{8})/', $line, $matches)) {
                $keyid = '0x' . $matches[1];
            } elseif ($keyid && preg_match('/^\s+[\s\w]+=\s*([\w\s]+)$/m', $line, $matches)) {
                $fingerprints[$keyid] = trim($matches[1]);
                $keyid = null;
            }
        }

        return $fingerprints;
    }

    /**
     * Encrypts text using PGP.
     *
     * @param string $text   The text to be PGP encrypted.
     * @param array $params  The parameters needed for encryption.
     *                       See the individual _encrypt*() functions for the
     *                       parameter requirements.
     *
     * @return string  The encrypted message.
     * @throws Horde_Crypt_Exception
     */
    public function encrypt($text, $params = array())
    {
        switch (isset($params['type']) ? $params['type'] : false) {
        case 'message':
            return $this->_encryptMessage($text, $params);

        case 'signature':
            return $this->_encryptSignature($text, $params);
        }
    }

    /**
     * Decrypts text using PGP.
     *
     * @param string $text   The text to be PGP decrypted.
     * @param array $params  The parameters needed for decryption.
     *                       See the individual _decrypt*() functions for the
     *                       parameter requirements.
     *
     * @return stdClass  An object with the following properties:
     *   - message: (string) The signature result text.
     *   - result: (boolean) The result of the signature test.
     *
     * @throws Horde_Crypt_Exception
     */
    public function decrypt($text, $params = array())
    {
        switch (isset($params['type']) ? $params['type'] : false) {
        case 'detached-signature':
        case 'signature':
            return $this->_decryptSignature($text, $params);

        case 'message':
            return $this->_decryptMessage($text, $params);
        }
    }

    /**
     * Returns whether a text has been encrypted symmetrically.
     *
     * @param string $text  The PGP encrypted text.
     *
     * @return boolean  True if the text is symmetrically encrypted.
     * @throws Horde_Crypt_Exception
     */
    public function encryptedSymmetrically($text)
    {
        $result = $this->_callGpg(
            array(
                '--decrypt',
                '--batch',
                '--passphrase ""'
            ),
            'w',
            $text,
            true,
            true,
            true,
            true
        );

        return (strpos($result->stderr, 'gpg: encrypted with 1 passphrase') !== false);
    }

    /**
     * Creates a temporary gpg keyring.
     *
     * @param string $type  The type of key to analyze. Either 'public'
     *                      (Default) or 'private'
     *
     * @return string  Command line keystring option to use with gpg program.
     */
    protected function _createKeyring($type = 'public')
    {
        switch (Horde_String::lower($type)) {
        case 'public':
            if (empty($this->_publicKeyring)) {
                $this->_publicKeyring = $this->_createTempFile('horde-pgp');
            }
            return '--keyring ' . $this->_publicKeyring;

        case 'private':
            if (empty($this->_privateKeyring)) {
                $this->_privateKeyring = $this->_createTempFile('horde-pgp');
            }
            return '--secret-keyring ' . $this->_privateKeyring;
        }
    }

    /**
     * Adds PGP keys to the keyring.
     *
     * @param mixed $keys   A single key or an array of key(s) to add to the
     *                      keyring.
     * @param string $type  The type of key(s) to add. Either 'public'
     *                      (Default) or 'private'
     *
     * @return string  Command line keystring option to use with gpg program.
     * @throws Horde_Crypt_Exception
     */
    protected function _putInKeyring($keys = array(), $type = 'public')
    {
        $type = Horde_String::lower($type);

        if (!is_array($keys)) {
            $keys = array($keys);
        }

        /* Gnupg v2: --secret-keyring is not used, so import everything into
         * the main keyring also. */
        if ($type == 'private') {
            $this->_putInKeyring($keys);
        }

        /* Create the keyrings if they don't already exist. */
        $keyring = $this->_createKeyring($type);

        /* Store the key(s) in the keyring. */
        $this->_callGpg(
            array(
                '--allow-secret-key-import',
                '--batch',
                '--fast-import',
                $keyring
            ),
            'w',
            array_values($keys)
        );

        return $keyring;
    }

    /**
     * Encrypts a message in PGP format using a public key.
     *
     * @param string $text   The text to be encrypted.
     * @param array $params  The parameters needed for encryption.
     *   - passphrase: The passphrase for the symmetric encryption (REQUIRED
     *                 if 'symmetric' is true)
     *   - recips: An array with the e-mail address of the recipient as the
     *             key and that person's public key as the value.
     *             (REQUIRED if 'symmetric' is false)
     *   - symmetric: Whether to use symmetric instead of asymmetric
     *                encryption (defaults to false).
     *   - type: [REQUIRED] 'message'
     *
     * @return string  The encrypted message.
     * @throws Horde_Crypt_Exception
     */
    protected function _encryptMessage($text, $params)
    {
        /* Create temp files for input. */
        $input = $this->_createTempFile('horde-pgp');
        file_put_contents($input, $text);

        /* Build command line. */
        $cmdline = array(
            '--armor',
            '--batch',
            '--always-trust'
        );

        if (empty($params['symmetric'])) {
            /* Store public key in temporary keyring. */
            $keyring = $this->_putInKeyring(array_values($params['recips']));

            $cmdline[] = $keyring;
            $cmdline[] = '--encrypt';
            foreach (array_keys($params['recips']) as $val) {
                $cmdline[] = '--recipient ' . $val;
            }
        } else {
            $cmdline[] = '--symmetric';
            $cmdline[] = '--passphrase-fd 0';
        }
        $cmdline[] = $input;

        /* Encrypt the document. */
        $result = $this->_callGpg(
            $cmdline,
            'w',
            empty($params['symmetric']) ? null : $params['passphrase'],
            true,
            true
        );

        if (!empty($result->output)) {
            return $result->output;
        }

        $error = preg_replace('/\n.*/', '', $result->stderr);
        throw new Horde_Crypt_Exception(
            Horde_Crypt_Translation::t("Could not PGP encrypt message: ") . $error
        );
    }

    /**
     * Signs a message in PGP format using a private key.
     *
     * @param string $text   The text to be signed.
     * @param array $params  The parameters needed for signing.
     *   - passphrase: [REQUIRED] Passphrase for PGP Key.
     *   - privkey: [REQUIRED] PGP private key.
     *   - pubkey: [REQUIRED] PGP public key.
     *   - sigtype: Determine the signature type to use.
     *              - 'cleartext': Make a clear text signature
     *              - 'detach': Make a detached signature (DEFAULT)
     *   - type: [REQUIRED] 'signature'
     *
     * @return string  The signed message.
     * @throws Horde_Crypt_Exception
     */
    protected function _encryptSignature($text, $params)
    {
        /* Check for required parameters. */
        if (!isset($params['pubkey']) ||
            !isset($params['privkey']) ||
            !isset($params['passphrase'])) {
            throw new Horde_Crypt_Exception(
                Horde_Crypt_Translation::t("A public PGP key, private PGP key, and passphrase are required to sign a message.")
            );
        }

        /* Create temp files for input. */
        $input = $this->_createTempFile('horde-pgp');

        /* Encryption requires both keyrings. */
        $pub_keyring = $this->_putInKeyring(array($params['pubkey']));
        $sec_keyring = $this->_putInKeyring(array($params['privkey']), 'private');

        /* Store message in temporary file. */
        file_put_contents($input, $text);

        /* Determine the signature type to use. */
        $cmdline = array();
        if (isset($params['sigtype']) &&
            $params['sigtype'] == 'cleartext') {
            $sign_type = '--clearsign';
        } else {
            $sign_type = '--detach-sign';
        }

        /* Additional GPG options. */
        $cmdline += array(
            '--armor',
            '--batch',
            '--passphrase-fd 0',
            $sec_keyring,
            $pub_keyring,
            $sign_type,
            $input
        );

        /* Sign the document. */
        $result = $this->_callGpg(
            $cmdline,
            'w',
            $params['passphrase'],
            true,
            true
        );

        if (!empty($result->output)) {
            return $result->output;
        }

        $error = preg_replace('/\n.*/', '', $result->stderr);
        throw new Horde_Crypt_Exception(
            Horde_Crypt_Translation::t("Could not PGP sign message: ") . $error
        );
    }

    /**
     * Decrypts an PGP encrypted message using a private/public keypair and a
     * passhprase.
     *
     * @param string $text   The text to be decrypted.
     * @param array $params  The parameters needed for decryption.
     *   - no_passphrase: Passphrase is not required.
     *   - passphrase: Passphrase for PGP Key. (REQUIRED, see no_passphrase)
     *   - privkey: PGP private key. (REQUIRED for asymmetric encryption)
     *   - pubkey: PGP public key. (REQUIRED for asymmetric encryption)
     *   - type: [REQUIRED] 'message'
     *
     * @return stdClass  An object with the following properties:
     *   - message: (string) The signature result text.
     *   - result: (boolean) The result of the signature test.
     *
     * @throws Horde_Crypt_Exception
     */
    protected function _decryptMessage($text, $params)
    {
        /* Check for required parameters. */
        if (!isset($params['passphrase']) && empty($params['no_passphrase'])) {
            throw new Horde_Crypt_Exception(
                Horde_Crypt_Translation::t("A passphrase is required to decrypt a message.")
            );
        }

        /* Create temp files. */
        $input = $this->_createTempFile('horde-pgp');

        /* Store message in file. */
        file_put_contents($input, $text);

        /* Build command line. */
        $cmdline = array(
            '--always-trust',
            '--armor',
            '--batch'
        );
        if (empty($params['no_passphrase'])) {
            $cmdline[] = '--passphrase-fd 0';
        }
        if (!empty($params['pubkey']) && !empty($params['privkey'])) {
            /* Decryption requires both keyrings. */
            $pub_keyring = $this->_putInKeyring(array($params['pubkey']));
            $sec_keyring = $this->_putInKeyring(array($params['privkey']), 'private');
            $cmdline[] = $sec_keyring;
            $cmdline[] = $pub_keyring;
        }
        $cmdline[] = '--decrypt';
        $cmdline[] = $input;

        $result = $this->_callGpg(
            $cmdline,
            empty($params['no_passphrase']) ? 'w' : 'r',
            empty($params['no_passphrase']) ? null : $params['passphrase'],
            true,
            true,
            true
        );

        if (!empty($result->output)) {
            return $this->_checkSignatureResult(
                $result->stderr,
                $result->output
            );
        }

        $error = preg_replace('/\n.*/', '', $result->stderr);
        throw new Horde_Crypt_Exception(
            Horde_Crypt_Translation::t("Could not decrypt PGP data: ") . $error
        );
    }

    /**
     * Decrypts an PGP signed message using a public key.
     *
     * @param string $text   The text to be verified.
     * @param array $params  The parameters needed for verification.
     *   - charset: Charset of the message body.
     *   - pubkey: [REQUIRED] PGP public key.
     *   - signature: PGP signature block. (REQUIRED for detached signature)
     *   - type: [REQUIRED] 'signature' or 'detached-signature'
     *
     * @return stdClass  An object with the following properties:
     *   - message: (string) The signature result text.
     *   - result: (boolean) The result of the signature test.
     *
     * @throws Horde_Crypt_Exception
     */
    protected function _decryptSignature($text, $params)
    {
        /* Check for required parameters. */
        if (!isset($params['pubkey'])) {
            throw new Horde_Crypt_Exception(
                Horde_Crypt_Translation::t("A public PGP key is required to verify a signed message.")
            );
        }
        if (($params['type'] === 'detached-signature') &&
            !isset($params['signature'])) {
            throw new Horde_Crypt_Exception(
                Horde_Crypt_Translation::t("The detached PGP signature block is required to verify the signed message.")
            );
        }

        /* Create temp files for input. */
        $input = $this->_createTempFile('horde-pgp');

        /* Store public key in temporary keyring. */
        $keyring = $this->_putInKeyring($params['pubkey']);

        /* Store the message in a temporary file. */
        file_put_contents($input, $text);

        /* Options for the GPG binary. */
        $cmdline = array(
            '--armor',
            '--always-trust',
            '--batch',
            '--charset ' . (isset($params['charset']) ? $params['charset'] : 'UTF-8'),
            $keyring,
            '--verify'
        );

        /* Extra stuff to do if we are using a detached signature. */
        if ($params['type'] === 'detached-signature') {
            $sigfile = $this->_createTempFile('horde-pgp');
            $cmdline[] = $sigfile . ' ' . $input;
            file_put_contents($sigfile, $params['signature']);
        } else {
            $cmdline[] = $input;
        }

        /* Verify the signature.  We need to catch standard error output,
         * since this is where the signature information is sent. */
        $result = $this->_callGpg($cmdline, 'r', null, true, true, true);
        return $this->_checkSignatureResult($result->stderr, $result->stderr);
    }

    /**
     * Checks signature result from the GnuPG binary.
     *
     * @param string $result   The signature result.
     * @param string $message  The decrypted message data.
     *
     * @return stdClass  An object with the following properties:
     *   - message: (string) The signature result text.
     *   - result: (string) The result of the signature test.
     *
     * @throws Horde_Crypt_Exception
     */
    protected function _checkSignatureResult($result, $message = null)
    {
        /* Good signature:
         *   gpg: Good signature from "blah blah blah (Comment)"
         * Bad signature:
         *   gpg: BAD signature from "blah blah blah (Comment)" */
        if (strpos($result, 'gpg: BAD signature') !== false) {
            throw new Horde_Crypt_Exception($result);
        }

        $ob = new stdClass;
        $ob->message = $message;
        $ob->result = $result;

        return $ob;
    }

    /**
     * Signs a MIME part using PGP.
     *
     * @param Horde_Mime_Part $mime_part  The object to sign.
     * @param array $params               The parameters required for signing.
     *                                    ({@see _encryptSignature()}).
     *
     * @return mixed  A Horde_Mime_Part object that is signed according to RFC
     *                3156.
     * @throws Horde_Crypt_Exception
     */
    public function signMIMEPart($mime_part, $params = array())
    {
        $params = array_merge($params, array(
            'sigtype' => 'detach',
            'type' => 'signature'
        ));

        /* RFC 3156 Requirements for a PGP signed message:
         * + Content-Type params 'micalg' & 'protocol' are REQUIRED.
         * + The digitally signed message MUST be constrained to 7 bits.
         * + The MIME headers MUST be a part of the signed data.
         * + Ensure there are no trailing spaces in encoded data by forcing
         *   text to be Q-P encoded (see, e.g., RFC 3676 [4.6]). */

        /* Ensure that all text parts are Q-P encoded. */
        foreach ($mime_part->contentTypeMap(false) as $key => $val) {
            if (strpos($val, 'text/') === 0) {
                $mime_part[$key]->setTransferEncoding('quoted-printable', array(
                    'send' => true
                ));
            }
        }

        /* Get the signature. */
        $msg_sign = $this->encrypt($mime_part->toString(array(
            'canonical' => true,
            'headers' => true
        )), $params);

        /* Add the PGP signature. */
        $pgp_sign = new Horde_Mime_Part();
        $pgp_sign->setType('application/pgp-signature');
        $pgp_sign->setHeaderCharset('UTF-8');
        $pgp_sign->setDisposition('inline');
        $pgp_sign->setDescription(
            Horde_Crypt_Translation::t("PGP Digital Signature")
        );
        $pgp_sign->setContents($msg_sign, array('encoding' => '7bit'));

        /* Get the algorithim information from the signature. Since we are
         * analyzing a signature packet, we need to use the special keyword
         * '_SIGNATURE' - see Horde_Crypt_Pgp. */
        $sig_info = $this->pgpPacketSignature($msg_sign, '_SIGNATURE');

        /* Setup the multipart MIME Part. */
        $part = new Horde_Mime_Part();
        $part->setType('multipart/signed');
        $part->setContents(
            "This message is in MIME format and has been PGP signed.\n"
        );
        $part->addPart($mime_part);
        $part->addPart($pgp_sign);
        $part->setContentTypeParameter(
            'protocol',
            'application/pgp-signature'
        );
        $part->setContentTypeParameter('micalg', $sig_info['micalg']);

        return $part;
    }

    /**
     * Encrypts a MIME part using PGP.
     *
     * @param Horde_Mime_Part $mime_part  The object to encrypt.
     * @param array $params               The parameters required for
     *                                    encryption
     *                                    ({@see _encryptMessage()}).
     *
     * @return mixed  A Horde_Mime_Part object that is encrypted according to
     *                RFC 3156.
     * @throws Horde_Crypt_Exception
     */
    public function encryptMIMEPart($mime_part, $params = array())
    {
        $params = array_merge($params, array('type' => 'message'));

        $signenc_body = $mime_part->toString(array(
            'canonical' => true,
            'headers' => true
        ));
        $message_encrypt = $this->encrypt($signenc_body, $params);

        /* Set up MIME Structure according to RFC 3156. */
        $part = new Horde_Mime_Part();
        $part->setType('multipart/encrypted');
        $part->setHeaderCharset('UTF-8');
        $part->setContentTypeParameter(
            'protocol',
            'application/pgp-encrypted'
        );
        $part->setDescription(
            Horde_Crypt_Translation::t("PGP Encrypted Data")
        );
        $part->setContents(
            "This message is in MIME format and has been PGP encrypted.\n"
        );

        $part1 = new Horde_Mime_Part();
        $part1->setType('application/pgp-encrypted');
        $part1->setCharset(null);
        $part1->setContents("Version: 1\n", array('encoding' => '7bit'));
        $part->addPart($part1);

        $part2 = new Horde_Mime_Part();
        $part2->setType('application/octet-stream');
        $part2->setCharset(null);
        $part2->setContents($message_encrypt, array('encoding' => '7bit'));
        $part2->setDisposition('inline');
        $part->addPart($part2);

        return $part;
    }

    /**
     * Signs and encrypts a MIME part using PGP.
     *
     * @param Horde_Mime_Part $mime_part   The object to sign and encrypt.
     * @param array $sign_params           The parameters required for
     *                                     signing
     *                                     ({@see _encryptSignature()}).
     * @param array $encrypt_params        The parameters required for
     *                                     encryption
     *                                     ({@see _encryptMessage()}).
     *
     * @return mixed  A Horde_Mime_Part object that is signed and encrypted
     *                according to RFC 3156.
     * @throws Horde_Crypt_Exception
     */
    public function signAndEncryptMIMEPart($mime_part, $sign_params = array(),
                                           $encrypt_params = array())
    {
        /* RFC 3156 requires that the entire signed message be encrypted.  We
         * need to explicitly call using Horde_Crypt_Pgp:: because we don't
         * know whether a subclass has extended these methods. */
        $part = $this->signMIMEPart($mime_part, $sign_params);
        $part = $this->encryptMIMEPart($part, $encrypt_params);
        $part->setContents(
            "This message is in MIME format and has been PGP signed and encrypted.\n"
        );

        $part->setCharset($this->_params['email_charset']);
        $part->setDescription(
            Horde_String::convertCharset(
                Horde_Crypt_Translation::t("PGP Signed/Encrypted Data"),
                'UTF-8',
                $this->_params['email_charset']
            )
        );

        return $part;
    }

    /**
     * Generates a Horde_Mime_Part object, in accordance with RFC 3156, that
     * contains a public key.
     *
     * @param string $key  The public key.
     *
     * @return Horde_Mime_Part  An object that contains the public key.
     */
    public function publicKeyMIMEPart($key)
    {
        $part = new Horde_Mime_Part();
        $part->setType('application/pgp-keys');
        $part->setHeaderCharset('UTF-8');
        $part->setDescription(Horde_Crypt_Translation::t("PGP Public Key"));
        $part->setContents($key, array('encoding' => '7bit'));

        return $part;
    }

    /**
     * Function that handles interfacing with the GnuPG binary.
     *
     * @param array $options      Options and commands to pass to GnuPG.
     * @param string $mode        'r' to read from stdout, 'w' to write to
     *                            stdin.
     * @param array $input        Input to write to stdin.
     * @param boolean $output     Collect and store output in object returned?
     * @param boolean $stderr     Collect and store stderr in object returned?
     * @param boolean $parseable  Is parseable output required? The gpg binary
     *                            would be executed with C locale then.
     * @param boolean $verbose    Run GnuPG with verbose flag?
     *
     * @return stdClass  Class with members output, stderr, and stdout.
     * @throws Horde_Crypt_Exception
     */
    protected function _callGpg($options, $mode, $input = array(),
                                $output = false, $stderr = false,
                                $parseable = false, $verbose = false)
    {
        $data = new stdClass;
        $data->output = null;
        $data->stderr = null;
        $data->stdout = null;

        /* Verbose output? */
        if (!$verbose) {
            array_unshift($options, '--quiet');
        }

        /* Create temp files for output. */
        if ($output) {
            $output_file = $this->_createTempFile('horde-pgp', false);
            array_unshift($options, '--output ' . $output_file);

            /* Do we need standard error output? */
            if ($stderr) {
                $stderr_file = $this->_createTempFile('horde-pgp', false);
                $options[] = '2> ' . $stderr_file;
            }
        }

        /* Silence errors if not requested. */
        if (!$output || !$stderr) {
            $options[] = '2> /dev/null';
        }

        /* Build the command line string now. */
        $cmdline = implode(' ', array_merge($this->_gnupg, $options));

        $language = getenv('LANGUAGE');
        if ($parseable) {
            putenv('LANGUAGE=C');
        }
        if ($mode == 'w') {
            if ($fp = popen($cmdline, 'w')) {
                putenv('LANGUAGE=' . $language);
                $win32 = !strncasecmp(PHP_OS, 'WIN', 3);

                if (!is_array($input)) {
                    $input = array($input);
                }

                foreach ($input as $line) {
                    if ($win32 && (strpos($line, "\x0d\x0a") !== false)) {
                        $chunks = explode("\x0d\x0a", $line);
                        foreach ($chunks as $chunk) {
                            fputs($fp, $chunk . "\n");
                        }
                    } else {
                        fputs($fp, $line . "\n");
                    }
                }
            } else {
                putenv('LANGUAGE=' . $language);
                throw new Horde_Crypt_Exception(Horde_Crypt_Translation::t("Error while talking to pgp binary."));
            }
        } elseif ($mode == 'r') {
            if ($fp = popen($cmdline, 'r')) {
                putenv('LANGUAGE=' . $language);
                while (!feof($fp)) {
                    $data->stdout .= fgets($fp, 1024);
                }
            } else {
                putenv('LANGUAGE=' . $language);
                throw new Horde_Crypt_Exception(Horde_Crypt_Translation::t("Error while talking to pgp binary."));
            }
        }
        pclose($fp);

        if ($output) {
            $data->output = file_get_contents($output_file);
            unlink($output_file);
            if ($stderr) {
                $data->stderr = file_get_contents($stderr_file);
                unlink($stderr_file);
            }
        }

        return $data;
    }

    /**
     * Generates a public key from a private key.
     *
     * @param string $data  Armor text of private key.
     *
     * @return string  Armor text of public key, or null if it could not be
     *                 generated.
     */
    public function getPublicKeyFromPrivateKey($data)
    {
        $this->_putInKeyring(array($data), 'private');
        $fingerprints = $this->getFingerprintsFromKey($data);
        reset($fingerprints);

        $cmdline = array(
            '--armor',
            '--export',
            key($fingerprints)
        );

        $result = $this->_callGpg($cmdline, 'r', array(), true, true);

        return empty($result->output)
            ? null
            : $result->output;
    }

    /* Deprecated components. */

    /**
     * @deprecated  Use Horde_Crypt_Pgp_Parse instead.
     */
    const ARMOR_MESSAGE = 1;
    const ARMOR_SIGNED_MESSAGE = 2;
    const ARMOR_PUBLIC_KEY = 3;
    const ARMOR_PRIVATE_KEY = 4;
    const ARMOR_SIGNATURE = 5;
    const ARMOR_TEXT = 6;

    /**
     * @deprecated  Use Horde_Crypt_Pgp_Parse instead.
     */
    protected $_armor = array(
        'MESSAGE' => self::ARMOR_MESSAGE,
        'SIGNED MESSAGE' => self::ARMOR_SIGNED_MESSAGE,
        'PUBLIC KEY BLOCK' => self::ARMOR_PUBLIC_KEY,
        'PRIVATE KEY BLOCK' => self::ARMOR_PRIVATE_KEY,
        'SIGNATURE' => self::ARMOR_SIGNATURE
    );

    /**
     * @deprecated  Use Horde_Crypt_Pgp_Keyserver instead.
     */
    const KEYSERVER_PUBLIC = 'pool.sks-keyservers.net';
    const KEYSERVER_REFUSE = 3;
    const KEYSERVER_TIMEOUT = 10;

    /**
     * @deprecated  Use Horde_Crypt_Pgp_Parse instead.
     */
    public function parsePGPData($text)
    {
        $parse = new Horde_Crypt_Pgp_Parse();
        return $parse->parse($text);
    }

    /**
     * @deprecated  Use Horde_Crypt_Pgp_Keyserver instead.
     */
    public function getPublicKeyserver($keyid,
                                       $server = self::KEYSERVER_PUBLIC,
                                       $timeout = self::KEYSERVER_TIMEOUT,
                                       $address = null)
    {
        $keyserver = $this->_getKeyserverOb($server);
        if (empty($keyid) && !empty($address)) {
            $keyid = $keyserver->getKeyID($address);
        }
        return $keyserver->get($keyid);
    }

    /**
     * @deprecated
     */
    public function generateRevocation($key, $email, $passphrase)
    {
        throw new Horde_Crypt_Exception('Not supported');
    }

    /**
     * @deprecated
     * @internal
     */
    protected function _getKeyserverOb($server)
    {
        $params = array(
            'keyserver' => $server,
            'http' => new Horde_Http_Client()
        );

        if (!empty($this->_params['proxy_host'])) {
            $params['http']->{'request.proxyServer'} = $this->_params['proxy_host'];
            if (isset($this->_params['proxy_port'])) {
                $params['http']->{'request.proxyPort'} = $this->_params['proxy_port'];
            }
        }

        return new Horde_Crypt_Pgp_Keyserver($this, $params);
    }

}
