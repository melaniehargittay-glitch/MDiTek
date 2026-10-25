<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
ob_start(); // capture any stray HTML output
require_once(dirname(__FILE__, 2) . "/globals.php");

use OpenEMR\Billing\BillingProcessor\X12RemoteTracker;

header('Content-Type: application/json');

$action  = $_POST['action'] ?? '';
$tracker = new X12RemoteTracker();

switch ($action) {

    case 'dispatch':
        try {
            ob_start();
            X12RemoteTracker::sftpSendWaitingFiles();
            $stray = ob_get_clean();
            echo json_encode([
                'success' => true,
                'message' => 'Dispatch complete',
                'debug'   => $stray  // will show any PHP warnings/errors
            ]);
        } catch (\Exception $e) {
            ob_get_clean();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        } catch (\Error $e) {
            ob_get_clean();
            echo json_encode(['success' => false, 'error' => 'Fatal: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()]);
        }
        break;

    case 'retry':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'Missing id']);
            break;
        }
        $record = $tracker->fetchById($id);
        if (!$record) {
            echo json_encode(['success' => false, 'error' => 'Record not found']);
            break;
        }
        $record['status']   = X12RemoteTracker::STATUS_WAITING;
        $record['messages'] = json_encode([]);
        $tracker->update($record);
        echo json_encode(['success' => true]);
        break;

    case 'fetch':
        $rows = $tracker->fetchAll();
        foreach ($rows as &$row) {
            if (!empty($row['messages']) && is_string($row['messages'])) {
                $row['messages'] = json_decode($row['messages'], true) ?? [];
            } else {
                $row['messages'] = [];
            }
        }
        echo json_encode(['success' => true, 'rows' => $rows]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
        break;
}
exit;