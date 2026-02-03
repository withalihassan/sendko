<?php
header('Content-Type: application/json; charset=utf-8');

/**
 * reload_instance.php
 *
 * Reboots (reloads) or stop+starts an EC2 instance using provided AWS credentials.
 * This script DOES NOT update any database rows.
 *
 * Required input (JSON body or POST):
 * - awsAccessKey
 * - awsSecretKey
 * - instance_id
 * - region
 *
 * Optional:
 * - reload_type: "reboot" (default) or "stopstart"
 *
 * Example JSON:
 * {
 *   "awsAccessKey":"AKIA...",
 *   "awsSecretKey":"secret",
 *   "instance_id":"i-0123456789abcdef0",
 *   "region":"us-east-1",
 *   "reload_type":"reboot"
 * }
 */

// read input JSON or POST form
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input) $input = $_POST ?? null;
if (!$input) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'no input received']);
    exit;
}

$awsAccessKey = $input['awsAccessKey'] ?? null;
$awsSecretKey = $input['awsSecretKey'] ?? null;
$instanceId   = $input['instance_id'] ?? null;
$region       = $input['region'] ?? null;
$reloadType   = strtolower($input['reload_type'] ?? 'reboot'); // reboot | stopstart

if (!$awsAccessKey || !$awsSecretKey || !$instanceId || !$region) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'missing required parameters (awsAccessKey/awsSecretKey/instance_id/region)']);
    exit;
}

// load AWS SDK autoloader - adjust path if needed
require_once __DIR__ . '/../../aws/aws-autoloader.php';

use Aws\Ec2\Ec2Client;
use Aws\Exception\AwsException;

// create EC2 client
try {
    $ec2 = new Ec2Client([
        'version'     => 'latest',
        'region'      => $region,
        'credentials' => [
            'key'    => $awsAccessKey,
            'secret' => $awsSecretKey,
        ],
        // Production: remove or set to true to verify SSL certs
        'http' => ['verify' => true]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'Failed creating EC2 client','error'=>$e->getMessage()]);
    exit;
}

try {
    if ($reloadType === 'stopstart') {
        // Stop instance
        $stopResp = $ec2->stopInstances(['InstanceIds'=>[$instanceId]]);
        // Start instance
        $startResp = $ec2->startInstances(['InstanceIds'=>[$instanceId]]);

        echo json_encode([
            'status' => 'ok',
            'message' => 'stop+start requested for instance',
            'instance_id' => $instanceId,
            'aws_action' => 'stopstart',
            'stop_response' => $stopResp->toArray(),
            'start_response' => $startResp->toArray()
        ]);
        exit;
    } else {
        // Default: reboot (soft reload)
        $resp = $ec2->rebootInstances(['InstanceIds'=>[$instanceId]]);

        // rebootInstances returns an empty result on success, include ResponseMetadata if available
        $out = method_exists($resp, 'toArray') ? $resp->toArray() : null;

        echo json_encode([
            'status' => 'ok',
            'message' => 'reboot (reload) requested for instance',
            'instance_id' => $instanceId,
            'aws_action' => 'reboot',
            'aws_response' => $out
        ]);
        exit;
    }
} catch (AwsException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'AWS API call failed',
        'aws_error' => $e->getAwsErrorMessage() ?? $e->getMessage()
    ]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unhandled error during reload operation',
        'error' => $e->getMessage()
    ]);
    exit;
}
