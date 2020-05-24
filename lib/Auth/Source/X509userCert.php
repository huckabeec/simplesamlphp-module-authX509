<?php

namespace SimpleSAML\Module\authX509\Auth\Source;

use Exception;
use SimpleSAML\Auth;
use SimpleSAML\Configuration;
use SimpleSAML\Error;
use SimpleSAML\Logger;
use SimpleSAML\Module\ldap\ConfigHelper;
use SimpleSAML\Utils;
use SimpleSAML\XHTML\Template;

/**
 * This class implements x509 certificate authentication with certificate validation against an LDAP directory.
 *
 * @author Emmanuel Dreyfus <manu@netbsd.org>
 * @package SimpleSAMLphp
 */

class X509userCert extends \SimpleSAML\Auth\Source
{
    /**
     * x509 attributes to use from the certificate for searching the user in the LDAP directory.
     * @var array
     */
    private $x509attributes = ['UID' => 'uid'];

    /**
     * A pattern from configuration to construct a ldap dn from a username
     * @var string|null
     */
    private $dnpattern;


    /**
     * LDAP attribute containing the user certificate.
     * This can be set to NULL to avoid looking up the certificate in LDAP
     * @var array|null
     */
    private $ldapusercert = ['userCertificate;binary'];


    /**
     * @var \SimpleSAML\Module\ldap\ConfigHelper
     */
    private $ldapcf;


    /**
     * Constructor for this authentication source.
     *
     * All subclasses who implement their own constructor must call this constructor before using $config for anything.
     *
     * @param array $info Information about this authentication source.
     * @param array &$config Configuration for this authentication source.
     */
    public function __construct(array $info, array &$config)
    {
        if (isset($config['authX509:x509attributes'])) {
            $this->x509attributes = $config['authX509:x509attributes'];
        }

        if (array_key_exists('authX509:ldapusercert', $config)) {
            $this->ldapusercert = $config['authX509:ldapusercert'];
        }

        if (isset($config['dnpattern'])) {
            $this->dnpattern = $config['dnpattern'];
        }

        parent::__construct($info, $config);

        $this->ldapcf = new ConfigHelper(
            $config,
            'Authentication source ' . var_export($this->authId, true)
        );
    }


    /**
     * Finish a failed authentication.
     *
     * This function can be overloaded by a child authentication class that wish to perform some operations on failure.
     *
     * @param array &$state Information about the current authentication.
     * @return void
     */
    public function authFailed(&$state): void
    {
        $config = Configuration::getInstance();

        $t = new Template($config, 'authX509:X509error.twig');
        $t->data['loginurl'] = Utils\HTTP::getSelfURL();
        $t->data['errorcode'] = $state['authX509.error'];
        $t->data['errorcodes'] = Error\ErrorCodes::getAllErrorCodeMessages();

        $t->send();
        exit();
    }


    /**
     * Validate certificate and login.
     *
     * This function try to validate the certificate. On success, the user is logged in without going through the login
     * page. On failure, The authX509:X509error.php template is loaded.
     *
     * @param array &$state Information about the current authentication.
     * @return void
     */
    public function authenticate(array &$state): void
    {
        $ldapcf = $this->ldapcf;

        if (
            !isset($_SERVER['SSL_CLIENT_CERT']) ||
            ($_SERVER['SSL_CLIENT_CERT'] == '')
        ) {
            $state['authX509.error'] = "NOCERT";
            $this->authFailed($state);

            throw new Exception("Should never be reached");
        }

        $client_cert = $_SERVER['SSL_CLIENT_CERT'];
        $client_cert_data = openssl_x509_parse($client_cert);
        if ($client_cert_data === false) {
            Logger::error('authX509: invalid cert');
            $state['authX509.error'] = "INVALIDCERT";
            $this->authFailed($state);

            throw new Exception("Should never be reached");
        }

        $dn = null;
        foreach ($this->x509attributes as $x509_attr => $ldap_attr) {
            // value is scalar
            if (array_key_exists($x509_attr, $client_cert_data['subject'])) {
                $value = $client_cert_data['subject'][$x509_attr];
                Logger::info('authX509: cert ' . $x509_attr . ' = ' . $value);

                if (isset($this->dnpattern)) {
                    $dn = str_replace('%username%', $value, $this->dnpattern);
                } else {
                    $dn = $ldapcf->searchfordn($ldap_attr, $value, true);
                }
                if ($dn !== null) {
                    break;
                }
            }
        }

        if ($dn === null) {
            Logger::error('authX509: cert has no matching user in LDAP.');
            $state['authX509.error'] = "UNKNOWNCERT";
            $this->authFailed($state);

            throw new Exception("Should never be reached");
        }

        if ($this->ldapusercert === null) {
            // do not check for certificate match
            $attributes = $ldapcf->getAttributes($dn);

            $state['Attributes'] = $attributes;
            $this->authSuccesful($state);

            throw new Exception("Should never be reached");
        }

        $ldap_certs = $ldapcf->getAttributes($dn, $this->ldapusercert);

        if (empty($ldap_certs)) {
            Logger::error('authX509: no certificate found in LDAP for dn=' . $dn);
            $state['authX509.error'] = "UNKNOWNCERT";
            $this->authFailed($state);

            throw new Exception("Should never be reached");
        }


        $merged_ldapcerts = [];
        foreach ($this->ldapusercert as $attr) {
            $merged_ldapcerts = array_merge($merged_ldapcerts, $ldap_certs[$attr]);
        }
        $ldap_certs = $merged_ldapcerts;

        foreach ($ldap_certs as $ldap_cert) {
            $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split($ldap_cert, 64, "\n") . "-----END CERTIFICATE-----\n";
            $ldap_cert_data = openssl_x509_parse($pem);
            if ($ldap_cert_data === false) {
                Logger::error('authX509: cert in LDAP is invalid for dn=' . $dn);
                continue;
            }

            if ($ldap_cert_data === $client_cert_data) {
                $attributes = $ldapcf->getAttributes($dn);
                $state['Attributes'] = $attributes;
                $this->authSuccesful($state);

                throw new Exception("Should never be reached");
            }
        }

        Logger::error('authX509: no matching cert in LDAP for dn=' . $dn);
        $state['authX509.error'] = "UNKNOWNCERT";
        $this->authFailed($state);

        throw new Exception("Should never be reached");
    }


    /**
     * Finish a successful authentication.
     *
     * This function can be overloaded by a child authentication class that wish to perform some operations after login.
     *
     * @param array &$state Information about the current authentication.
     * @return void
     */
    public function authSuccesful(array &$state): void
    {
        Auth\Source::completeAuth($state);

        throw new Exception("Should never be reached");
    }
}
