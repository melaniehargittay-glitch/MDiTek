<?php

/**
 * Model that provides tracking information for a run of claim processing using
 * the billing manager. Each run is saved as an entry in the x12_remote_tracker
 * table. Sends files to Availity (or any X12 partner) via SFTP using
 * username + password only — no SSH key required.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Ken Chapple <ken@mi-squared.com>
 * @copyright Copyright (c) 2021 Ken Chapple <ken@mi-squared.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Billing\BillingProcessor;

use OpenEMR\Common\Crypto\CryptoGen;
use OpenEMR\Services\BaseService;
use phpseclib3\Net\SFTP;

class X12RemoteTracker extends BaseService
{
    const STATUS_WAITING        = 'waiting';
    const STATUS_PARAMETER_ERROR = 'parameter-error';
    const STATUS_CLAIM_FILE_ERROR = 'claim-file-error';
    const STATUS_LOGIN_ERROR    = 'login-error';
    const STATUS_CHDIR_ERROR    = 'chdir-error';
    const STATUS_IN_PROGRESS    = 'in-progress';
    const STATUS_UPLOAD_ERROR   = 'upload-error';   // fixed typo: was UPLOAD_ERRROR
    const STATUS_SUCCESS        = 'success';

    const TABLE_NAME = 'x12_remote_tracker';

    /**
     * Required fields on the x12_partners record.
     * x12_sftp_local_dir is optional (falls back to OE_SITE_DIR/documents/edi/).
     */
    protected static $x12_partner_field_keys = [
        'x12_sftp_host'       => 'X12 SFTP Host',
        'x12_sftp_port'       => 'X12 SFTP Port',
        'x12_sftp_login'      => 'X12 SFTP Login',
        'x12_sftp_pass'       => 'X12 SFTP Password',
        'x12_sftp_remote_dir' => 'X12 SFTP Remote Dir',
    ];

    const SELECT = "SELECT R.id, R.x12_filename, R.status, R.messages, R.claims,
       R.created_at, R.updated_at,
       P.name, P.id AS x12_partner_id,
       P.x12_sftp_host, P.x12_sftp_port, P.x12_sftp_login, P.x12_sftp_pass,
       P.x12_sftp_remote_dir, P.x12_sftp_local_dir
       FROM x12_remote_tracker R";

    protected $validationMessages = [];

    public function __construct()
    {
        parent::__construct(self::TABLE_NAME);
    }

    // -------------------------------------------------------------------------
    // Main SFTP dispatch — called by the cron / batch processor
    // -------------------------------------------------------------------------

    public static function sftpSendWaitingFiles(): void
    {
        $remoteTracker = new self();
        $x12_remotes   = $remoteTracker->fetchByStatus(self::STATUS_WAITING);
        $cryptoGen     = new CryptoGen();

        foreach ($x12_remotes as $x12_remote) {

            // Decode stored messages JSON if needed
            if (!empty($x12_remote['messages']) && is_string($x12_remote['messages'])) {
                $x12_remote['messages'] = json_decode($x12_remote['messages'], true) ?? [];
            } else {
                $x12_remote['messages'] = $x12_remote['messages'] ?? [];
            }

            // ------------------------------------------------------------------
            // 1. Validate required SFTP credentials exist on the partner record
            // ------------------------------------------------------------------
            if (false === $remoteTracker->validateSFTPCredentials($x12_remote)) {
                $x12_remote['status']   = self::STATUS_PARAMETER_ERROR;
                $x12_remote['messages'] = array_merge(
                    $x12_remote['messages'],
                    $remoteTracker->validationMessages
                );
                $remoteTracker->update($x12_remote);
                continue;
            }

            // ------------------------------------------------------------------
            // 2. Decrypt the stored password (CryptoGen handles the decryption)
            // ------------------------------------------------------------------
            $decrypted_password = $cryptoGen->decryptStandard($x12_remote['x12_sftp_pass']);
            if (empty($decrypted_password)) {
                // Password may be stored in plain text (older installs) — use as-is
                $decrypted_password = $x12_remote['x12_sftp_pass'];
            }

            // ------------------------------------------------------------------
            // 3. Locate the local claim file
            // ------------------------------------------------------------------
            $claim_file = '';
            if (!empty($x12_remote['x12_sftp_local_dir'])) {
                $candidate = rtrim($x12_remote['x12_sftp_local_dir'], '/') . '/' . $x12_remote['x12_filename'];
                if (file_exists($candidate)) {
                    $claim_file = $candidate;
                }
            }
            if (empty($claim_file)) {
                $candidate = $GLOBALS['OE_SITE_DIR'] . "/documents/edi/" . $x12_remote['x12_filename'];
                if (file_exists($candidate)) {
                    $claim_file = $candidate;
                }
            }

            $claim_file_contents = $claim_file ? @file_get_contents($claim_file) : false;
            if ($claim_file_contents === false) {
                $x12_remote['status']     = self::STATUS_CLAIM_FILE_ERROR;
                $x12_remote['messages'][] = "Could not open local claim file: `$claim_file`";
                $remoteTracker->update($x12_remote);
                continue;
            }

            // ------------------------------------------------------------------
            // 4. Connect & authenticate via SFTP (password only, no key)
            // ------------------------------------------------------------------
            try {
                $sftp = new SFTP($x12_remote['x12_sftp_host'], (int)$x12_remote['x12_sftp_port']);

                if (!$sftp->login($x12_remote['x12_sftp_login'], $decrypted_password)) {
                    $x12_remote['status']     = self::STATUS_LOGIN_ERROR;
                    $x12_remote['messages'][] = "Invalid Username or Password.";
                    $remoteTracker->update($x12_remote);
                    continue;
                }
            } catch (\Exception $e) {
                $x12_remote['status']     = self::STATUS_LOGIN_ERROR;
                $x12_remote['messages'][] = "SFTP connection failed: " . $e->getMessage();
                $remoteTracker->update($x12_remote);
                continue;
            }

            // ------------------------------------------------------------------
            // 5. Change to remote directory
            // ------------------------------------------------------------------
            if (false === $sftp->chdir($x12_remote['x12_sftp_remote_dir'])) {
                $x12_remote['status']     = self::STATUS_CHDIR_ERROR;
                $x12_remote['messages'][] = "Could not change to SFTP remote directory: "
                    . $x12_remote['x12_sftp_remote_dir'];
                $remoteTracker->update($x12_remote);
                $sftp->disconnect();
                continue;
            }

            // ------------------------------------------------------------------
            // 6. Mark in-progress, then upload
            // ------------------------------------------------------------------
            $x12_remote['status'] = self::STATUS_IN_PROGRESS;
            $remoteTracker->update($x12_remote);

            if (false === $sftp->put($x12_remote['x12_filename'], $claim_file_contents)) {
                $x12_remote['status']     = self::STATUS_UPLOAD_ERROR;
                $x12_remote['messages'][] = "Could not upload file to remote server.";
                $remoteTracker->update($x12_remote);
                $sftp->disconnect();
                continue;   // <-- BUG FIX: original code fell through to STATUS_SUCCESS
            }

            // ------------------------------------------------------------------
            // 7. Success
            // ------------------------------------------------------------------
            $x12_remote['status'] = self::STATUS_SUCCESS;
            $remoteTracker->update($x12_remote);
            $sftp->disconnect();
        }
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    protected function validateSFTPCredentials(array $credentials): bool
    {
        $this->validationMessages = [];
        $valid = true;
        foreach (self::$x12_partner_field_keys as $key => $label) {
            if (empty($credentials[$key])) {
                $this->validationMessages[] = "`$label` is required";
                $valid = false;
            }
        }
        return $valid;
    }

    // -------------------------------------------------------------------------
    // CRUD helpers
    // -------------------------------------------------------------------------

    public static function create(array $fields): int
    {
        $fields['created_at'] = date('Y-m-d H:i:s');
        $fields['updated_at'] = date('Y-m-d H:i:s');
        $remoteTracker = new self();
        return $remoteTracker->insert($fields);
    }

    public function insert(array $fields): int
    {
        $setQueryPart = $this->buildInsertColumns($this->onlyRealFields($fields));
        $sql  = "INSERT INTO x12_remote_tracker SET ";
        $sql .= $setQueryPart['set'];
        return sqlInsert($sql, $setQueryPart['bind']);
    }

    public function update(array $fields): void
    {
        if (is_array($fields['messages'] ?? null)) {
            $fields['messages'] = json_encode($fields['messages']);
        }
        $fields['updated_at'] = date('Y-m-d H:i:s');
        $query  = $this->buildUpdateColumns($this->onlyRealFields($fields));
        $sql    = "UPDATE x12_remote_tracker SET ";
        $sql   .= $query['set'];
        $sql   .= "WHERE id = ?";
        array_push($query['bind'], $fields['id']);
        sqlStatement($sql, $query['bind']);
    }

    protected function onlyRealFields(array $passed_in): array
    {
        $realFields = [];
        foreach ($passed_in as $key => $value) {
            if (in_array($key, $this->getFields())) {
                $realFields[$key] = $value;
            }
        }
        return $realFields;
    }

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    public function fetchByStatus(string $status = self::STATUS_WAITING): array
    {
        return self::selectHelper(self::SELECT, [
            'join'  => "JOIN x12_partners P ON P.id = R.x12_partner_id",
            'where' => "WHERE R.`status` = ?",
            'order' => 'ORDER BY R.created_at DESC',
            'data'  => [$status],
        ]);
    }

    public function fetchAll(): array
    {
        return self::selectHelper(self::SELECT, [
            'join'  => "JOIN x12_partners P ON P.id = R.x12_partner_id",
            'order' => 'ORDER BY R.updated_at DESC',
        ]);
    }

    public function fetchById(int $id): ?array
    {
        $results = self::selectHelper(self::SELECT, [
            'join'  => "JOIN x12_partners P ON P.id = R.x12_partner_id",
            'where' => "WHERE R.id = ?",
            'data'  => [$id],
        ]);
        return $results[0] ?? null;
    }
}
