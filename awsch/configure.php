<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../db.php';
require '../aws/aws-autoloader.php';

use Aws\Account\AccountClient;
use Aws\Exception\AwsException;
use Aws\Organizations\OrganizationsClient;

$child_id = trim($_GET['ac_id'] ?? '');
$parent_id = trim($_GET['parent_id'] ?? '');

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function sendJson($success, $message, $extra = [])
{
    header('Content-Type: application/json');
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra));
    exit;
}

function countries()
{
    return [
        'AF' => 'Afghanistan', 'AX' => 'Aland Islands', 'AL' => 'Albania', 'DZ' => 'Algeria', 'AS' => 'American Samoa',
        'AD' => 'Andorra', 'AO' => 'Angola', 'AI' => 'Anguilla', 'AQ' => 'Antarctica', 'AG' => 'Antigua and Barbuda',
        'AR' => 'Argentina', 'AM' => 'Armenia', 'AW' => 'Aruba', 'AU' => 'Australia', 'AT' => 'Austria',
        'AZ' => 'Azerbaijan', 'BS' => 'Bahamas', 'BH' => 'Bahrain', 'BD' => 'Bangladesh', 'BB' => 'Barbados',
        'BY' => 'Belarus', 'BE' => 'Belgium', 'BZ' => 'Belize', 'BJ' => 'Benin', 'BM' => 'Bermuda',
        'BT' => 'Bhutan', 'BO' => 'Bolivia', 'BQ' => 'Bonaire, Sint Eustatius and Saba', 'BA' => 'Bosnia and Herzegovina',
        'BW' => 'Botswana', 'BV' => 'Bouvet Island', 'BR' => 'Brazil', 'IO' => 'British Indian Ocean Territory',
        'BN' => 'Brunei Darussalam', 'BG' => 'Bulgaria', 'BF' => 'Burkina Faso', 'BI' => 'Burundi',
        'KH' => 'Cambodia', 'CM' => 'Cameroon', 'CA' => 'Canada', 'CV' => 'Cape Verde', 'KY' => 'Cayman Islands',
        'CF' => 'Central African Republic', 'TD' => 'Chad', 'CL' => 'Chile', 'CN' => 'China', 'CX' => 'Christmas Island',
        'CC' => 'Cocos Islands', 'CO' => 'Colombia', 'KM' => 'Comoros', 'CG' => 'Congo', 'CD' => 'Congo, Democratic Republic',
        'CK' => 'Cook Islands', 'CR' => 'Costa Rica', 'CI' => "Cote d'Ivoire", 'HR' => 'Croatia', 'CU' => 'Cuba',
        'CW' => 'Curacao', 'CY' => 'Cyprus', 'CZ' => 'Czech Republic', 'DK' => 'Denmark', 'DJ' => 'Djibouti',
        'DM' => 'Dominica', 'DO' => 'Dominican Republic', 'EC' => 'Ecuador', 'EG' => 'Egypt', 'SV' => 'El Salvador',
        'GQ' => 'Equatorial Guinea', 'ER' => 'Eritrea', 'EE' => 'Estonia', 'SZ' => 'Eswatini', 'ET' => 'Ethiopia',
        'FK' => 'Falkland Islands', 'FO' => 'Faroe Islands', 'FJ' => 'Fiji', 'FI' => 'Finland', 'FR' => 'France',
        'GF' => 'French Guiana', 'PF' => 'French Polynesia', 'TF' => 'French Southern Territories', 'GA' => 'Gabon',
        'GM' => 'Gambia', 'GE' => 'Georgia', 'DE' => 'Germany', 'GH' => 'Ghana', 'GI' => 'Gibraltar',
        'GR' => 'Greece', 'GL' => 'Greenland', 'GD' => 'Grenada', 'GP' => 'Guadeloupe', 'GU' => 'Guam',
        'GT' => 'Guatemala', 'GG' => 'Guernsey', 'GN' => 'Guinea', 'GW' => 'Guinea-Bissau', 'GY' => 'Guyana',
        'HT' => 'Haiti', 'HM' => 'Heard Island and McDonald Islands', 'VA' => 'Holy See', 'HN' => 'Honduras',
        'HK' => 'Hong Kong', 'HU' => 'Hungary', 'IS' => 'Iceland', 'IN' => 'India', 'ID' => 'Indonesia',
        'IR' => 'Iran', 'IQ' => 'Iraq', 'IE' => 'Ireland', 'IM' => 'Isle of Man', 'IL' => 'Israel',
        'IT' => 'Italy', 'JM' => 'Jamaica', 'JP' => 'Japan', 'JE' => 'Jersey', 'JO' => 'Jordan',
        'KZ' => 'Kazakhstan', 'KE' => 'Kenya', 'KI' => 'Kiribati', 'KP' => 'Korea, North', 'KR' => 'Korea, South',
        'KW' => 'Kuwait', 'KG' => 'Kyrgyzstan', 'LA' => 'Laos', 'LV' => 'Latvia', 'LB' => 'Lebanon',
        'LS' => 'Lesotho', 'LR' => 'Liberia', 'LY' => 'Libya', 'LI' => 'Liechtenstein', 'LT' => 'Lithuania',
        'LU' => 'Luxembourg', 'MO' => 'Macao', 'MG' => 'Madagascar', 'MW' => 'Malawi', 'MY' => 'Malaysia',
        'MV' => 'Maldives', 'ML' => 'Mali', 'MT' => 'Malta', 'MH' => 'Marshall Islands', 'MQ' => 'Martinique',
        'MR' => 'Mauritania', 'MU' => 'Mauritius', 'YT' => 'Mayotte', 'MX' => 'Mexico', 'FM' => 'Micronesia',
        'MD' => 'Moldova', 'MC' => 'Monaco', 'MN' => 'Mongolia', 'ME' => 'Montenegro', 'MS' => 'Montserrat',
        'MA' => 'Morocco', 'MZ' => 'Mozambique', 'MM' => 'Myanmar', 'NA' => 'Namibia', 'NR' => 'Nauru',
        'NP' => 'Nepal', 'NL' => 'Netherlands', 'NC' => 'New Caledonia', 'NZ' => 'New Zealand', 'NI' => 'Nicaragua',
        'NE' => 'Niger', 'NG' => 'Nigeria', 'NU' => 'Niue', 'NF' => 'Norfolk Island', 'MK' => 'North Macedonia',
        'MP' => 'Northern Mariana Islands', 'NO' => 'Norway', 'OM' => 'Oman', 'PK' => 'Pakistan', 'PW' => 'Palau',
        'PS' => 'Palestine', 'PA' => 'Panama', 'PG' => 'Papua New Guinea', 'PY' => 'Paraguay', 'PE' => 'Peru',
        'PH' => 'Philippines', 'PN' => 'Pitcairn', 'PL' => 'Poland', 'PT' => 'Portugal', 'PR' => 'Puerto Rico',
        'QA' => 'Qatar', 'RE' => 'Reunion', 'RO' => 'Romania', 'RU' => 'Russian Federation', 'RW' => 'Rwanda',
        'BL' => 'Saint Barthelemy', 'SH' => 'Saint Helena', 'KN' => 'Saint Kitts and Nevis', 'LC' => 'Saint Lucia',
        'MF' => 'Saint Martin', 'PM' => 'Saint Pierre and Miquelon', 'VC' => 'Saint Vincent and the Grenadines',
        'WS' => 'Samoa', 'SM' => 'San Marino', 'ST' => 'Sao Tome and Principe', 'SA' => 'Saudi Arabia',
        'SN' => 'Senegal', 'RS' => 'Serbia', 'SC' => 'Seychelles', 'SL' => 'Sierra Leone', 'SG' => 'Singapore',
        'SX' => 'Sint Maarten', 'SK' => 'Slovakia', 'SI' => 'Slovenia', 'SB' => 'Solomon Islands',
        'SO' => 'Somalia', 'ZA' => 'South Africa', 'GS' => 'South Georgia and the South Sandwich Islands',
        'SS' => 'South Sudan', 'ES' => 'Spain', 'LK' => 'Sri Lanka', 'SD' => 'Sudan', 'SR' => 'Suriname',
        'SJ' => 'Svalbard and Jan Mayen', 'SE' => 'Sweden', 'CH' => 'Switzerland', 'SY' => 'Syrian Arab Republic',
        'TW' => 'Taiwan', 'TJ' => 'Tajikistan', 'TZ' => 'Tanzania', 'TH' => 'Thailand', 'TL' => 'Timor-Leste',
        'TG' => 'Togo', 'TK' => 'Tokelau', 'TO' => 'Tonga', 'TT' => 'Trinidad and Tobago', 'TN' => 'Tunisia',
        'TR' => 'Turkey', 'TM' => 'Turkmenistan', 'TC' => 'Turks and Caicos Islands', 'TV' => 'Tuvalu',
        'UG' => 'Uganda', 'UA' => 'Ukraine', 'AE' => 'United Arab Emirates', 'GB' => 'United Kingdom',
        'US' => 'United States', 'UM' => 'United States Minor Outlying Islands', 'UY' => 'Uruguay',
        'UZ' => 'Uzbekistan', 'VU' => 'Vanuatu', 'VE' => 'Venezuela', 'VN' => 'Vietnam',
        'VG' => 'Virgin Islands, British', 'VI' => 'Virgin Islands, U.S.', 'WF' => 'Wallis and Futuna',
        'EH' => 'Western Sahara', 'YE' => 'Yemen', 'ZM' => 'Zambia', 'ZW' => 'Zimbabwe',
    ];
}

function getChildAccount(PDO $pdo, $accountId)
{
    $stmt = $pdo->prepare("SELECT * FROM `child_accounts` WHERE `account_id` = ? LIMIT 1");
    $stmt->execute([$accountId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getParentAccount(PDO $pdo, $accountId)
{
    $stmt = $pdo->prepare("SELECT * FROM `accounts` WHERE `account_id` = ? LIMIT 1");
    $stmt->execute([$accountId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getParentCredentials($parentAccount)
{
    $accessKey = $parentAccount['aws_key'] ?? $parentAccount['aws_access_key'] ?? $parentAccount['access_key_id'] ?? '';
    $secretKey = $parentAccount['aws_secret'] ?? $parentAccount['aws_secret_key'] ?? $parentAccount['secret_access_key'] ?? '';

    if ($accessKey === '' || $secretKey === '') {
        return null;
    }

    return [
        'key' => $accessKey,
        'secret' => $secretKey,
    ];
}

function loadContext(PDO $pdo, $childId, $parentId = '')
{
    if ($childId === '' || !preg_match('/^\d{12}$/', $childId)) {
        throw new Exception('A valid AWS member account id is required.');
    }

    $childAccount = getChildAccount($pdo, $childId);
    if (!$childAccount) {
        throw new Exception('Member account was not found in the database.');
    }

    $effectiveParentId = $parentId ?: ($childAccount['parent_id'] ?? '');
    if ($effectiveParentId === '') {
        throw new Exception('Parent account is missing for this member account.');
    }

    $parentAccount = getParentAccount($pdo, $effectiveParentId);
    if (!$parentAccount) {
        throw new Exception('Parent account was not found in the database.');
    }

    $credentials = getParentCredentials($parentAccount);
    if (!$credentials) {
        throw new Exception('Parent AWS credentials were not found in the database.');
    }

    return [$childAccount, $effectiveParentId, $credentials];
}

function accountClient($credentials)
{
    return new AccountClient([
        'version' => 'latest',
        'region' => 'us-east-1',
        'credentials' => $credentials,
    ]);
}

function organizationsClient($credentials)
{
    return new OrganizationsClient([
        'version' => 'latest',
        'region' => 'us-east-1',
        'credentials' => $credentials,
    ]);
}

function isAccountManagementTrustedAccessEnabled(OrganizationsClient $orgClient)
{
    $nextToken = null;

    do {
        $params = [];
        if ($nextToken) {
            $params['NextToken'] = $nextToken;
        }

        $result = $orgClient->listAWSServiceAccessForOrganization($params);
        foreach (($result['EnabledServicePrincipals'] ?? []) as $servicePrincipal) {
            if (($servicePrincipal['ServicePrincipal'] ?? '') === 'account.amazonaws.com') {
                return true;
            }
        }

        $nextToken = $result['NextToken'] ?? null;
    } while ($nextToken);

    return false;
}

function enableAccountManagementTrustedAccess($credentials)
{
    $orgClient = organizationsClient($credentials);

    if (!isAccountManagementTrustedAccessEnabled($orgClient)) {
        $orgClient->enableAWSServiceAccess([
            'ServicePrincipal' => 'account.amazonaws.com',
        ]);
    }

    for ($i = 0; $i < 6; $i++) {
        if (isAccountManagementTrustedAccessEnabled($orgClient)) {
            return;
        }
        sleep(2);
    }

    throw new Exception('Trusted access for AWS Account Management was requested but could not be verified.');
}

function requiredPost($key, $label)
{
    $value = trim($_POST[$key] ?? '');
    if ($value === '') {
        sendJson(false, $label . ' is required.');
    }
    return $value;
}

function buildContactInformationFromPost()
{
    $countryCode = strtoupper(requiredPost('country', 'Country'));
    if (!isset(countries()[$countryCode])) {
        sendJson(false, 'Select a valid country.');
    }

    $phone = requiredPost('phone_number', 'Phone number');
    if (!preg_match('/^[+][\s0-9()-]+$/', $phone)) {
        sendJson(false, 'Phone number must start with + and contain only numbers, spaces, dashes, or parentheses.');
    }

    $contact = [
        'FullName' => requiredPost('full_name', 'Full name'),
        'AddressLine1' => requiredPost('street_address', 'Street address'),
        'City' => requiredPost('city', 'City'),
        'StateOrRegion' => requiredPost('state', 'State'),
        'PostalCode' => requiredPost('postal_code', 'Postal code'),
        'PhoneNumber' => $phone,
        'CountryCode' => $countryCode,
    ];

    $optional = [
        'address_line_2' => 'AddressLine2',
        'company_name' => 'CompanyName',
    ];

    foreach ($optional as $postKey => $awsKey) {
        $value = trim($_POST[$postKey] ?? '');
        if ($value !== '') {
            $contact[$awsKey] = $value;
        }
    }

    return $contact;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $postedChildId = trim($_POST['child_account_id'] ?? '');
    $postedParentId = trim($_POST['parent_account_id'] ?? '');

    try {
        [$childAccount, $effectiveParentId, $credentials] = loadContext($pdo, $postedChildId, $postedParentId);
        $client = accountClient($credentials);

        if ($action === 'attach_secret_policy') {
            enableAccountManagementTrustedAccess($credentials);
            sendJson(true, 'Policy Attached Successfully, now you can Perform Actions');
        }

        if ($action === 'get_contact') {
            $result = $client->getContactInformation([
                'AccountId' => $postedChildId,
            ]);
            sendJson(true, 'Primary contact details loaded.', [
                'contact' => $result['ContactInformation'] ?? [],
            ]);
        }

        if ($action === 'update_contact') {
            $client->putContactInformation([
                'AccountId' => $postedChildId,
                'ContactInformation' => buildContactInformationFromPost(),
            ]);
            sendJson(true, 'Base IP contact details updated successfully.');
        }

        if ($action === 'send_email_otp') {
            $newEmail = trim($_POST['new_email'] ?? '');
            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                sendJson(false, 'Enter a valid email address.');
            }

            $result = $client->startPrimaryEmailUpdate([
                'AccountId' => $postedChildId,
                'PrimaryEmail' => $newEmail,
            ]);

            sendJson(true, 'AWS sent a verification OTP to ' . $newEmail . '.', [
                'status' => $result['Status'] ?? 'PENDING',
            ]);
        }

        if ($action === 'verify_email_otp') {
            $newEmail = trim($_POST['new_email'] ?? '');
            $accountName = trim($_POST['account_name'] ?? '');
            $otp = trim($_POST['otp'] ?? '');

            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                sendJson(false, 'Enter a valid email address.');
            }

            if ($accountName !== '' && strlen($accountName) > 128) {
                sendJson(false, 'Account name must be 128 characters or fewer.');
            }

            if (!preg_match('/^[A-Za-z0-9]{6}$/', $otp)) {
                sendJson(false, 'Enter the 6-character OTP from the AWS email.');
            }

            $result = $client->acceptPrimaryEmailUpdate([
                'AccountId' => $postedChildId,
                'PrimaryEmail' => $newEmail,
                'Otp' => $otp,
            ]);

            if ($accountName !== '' && $accountName !== ($childAccount['name'] ?? '')) {
                $stmt = $pdo->prepare("UPDATE `child_accounts` SET `email` = ?, `name` = ? WHERE `account_id` = ?");
                $stmt->execute([$newEmail, $accountName, $postedChildId]);
                $message = 'Email and account name updated successfully for member account ' . $postedChildId . '.';
            } else {
                $stmt = $pdo->prepare("UPDATE `child_accounts` SET `email` = ? WHERE `account_id` = ?");
                $stmt->execute([$newEmail, $postedChildId]);
                $message = 'Email updated successfully for member account ' . $postedChildId . '.';
            }

            sendJson(true, $message, [
                'status' => $result['Status'] ?? 'ACCEPTED',
            ]);
        }

        sendJson(false, 'Invalid request action.');
    } catch (AwsException $e) {
        sendJson(false, 'AWS Error: ' . ($e->getAwsErrorMessage() ?: $e->getMessage()));
    } catch (PDOException $e) {
        sendJson(false, 'Database Error: ' . $e->getMessage());
    } catch (Exception $e) {
        sendJson(false, $e->getMessage());
    }
}

try {
    [$childAccount, $parent_id, $parentCredentials] = loadContext($pdo, $child_id, $parent_id);
    $currentEmail = $childAccount['email'] ?? '';
    $currentName = $childAccount['name'] ?? '';
} catch (Exception $e) {
    die("<div class='alert alert-danger m-4'>" . h($e->getMessage()) . "</div>");
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Configure Account</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body {
            background-color: #f8f9fa;
            font-family: Arial, sans-serif;
        }

        .response-box {
            border: 1px solid #dee2e6;
            padding: 15px;
            border-radius: 8px;
            background-color: #fff;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>

<body>
    <div class="container mt-4">
        <div id="response" class="response-box mb-4">
            Ready to configure account <?php echo h($child_id); ?>.
        </div>

        <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-warning" id="attachSecretPolicyBtn">
                Attach Secret Policy
            </button>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#updateEmailModal">
                Update Email
            </button>
            <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#updateBaseIpModal">
                Update Base IP
            </button>
        </div>
    </div>

    <div class="modal fade" id="updateEmailModal" tabindex="-1" aria-labelledby="updateEmailModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form id="updateEmailForm" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="updateEmailModalLabel">Update Email</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="child_account_id" value="<?php echo h($child_id); ?>">
                    <input type="hidden" name="parent_account_id" value="<?php echo h($parent_id); ?>">

                    <div class="mb-3">
                        <label class="form-label">Current Email</label>
                        <input type="email" class="form-control" value="<?php echo h($currentEmail); ?>" readonly>
                    </div>

                    <div class="mb-3">
                        <label for="accountName" class="form-label">Account Name</label>
                        <input type="text" class="form-control" id="accountName" name="account_name" value="<?php echo h($currentName); ?>" maxlength="128">
                    </div>

                    <div class="mb-3">
                        <label for="newEmail" class="form-label">New Email</label>
                        <input type="email" class="form-control" id="newEmail" name="new_email" required>
                    </div>

                    <div class="mb-3 d-none" id="otpGroup">
                        <label for="otp" class="form-label">OTP</label>
                        <input type="text" class="form-control" id="otp" name="otp" maxlength="6" autocomplete="one-time-code">
                    </div>

                    <div id="modalMessage" class="small text-muted">
                        Enter the new email address to receive the AWS verification OTP.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary" id="submitEmailBtn">Send OTP</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="updateBaseIpModal" tabindex="-1" aria-labelledby="updateBaseIpModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <form id="updateBaseIpForm" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="updateBaseIpModalLabel">Update Base IP</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="child_account_id" value="<?php echo h($child_id); ?>">
                    <input type="hidden" name="parent_account_id" value="<?php echo h($parent_id); ?>">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Full name</label>
                            <input type="text" class="form-control" name="full_name" data-contact="FullName" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Company name</label>
                            <input type="text" class="form-control" name="company_name" data-contact="CompanyName">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Street address</label>
                            <input type="text" class="form-control" name="street_address" data-contact="AddressLine1" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Address line 2</label>
                            <input type="text" class="form-control" name="address_line_2" data-contact="AddressLine2">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">City</label>
                            <input type="text" class="form-control" name="city" data-contact="City" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">State</label>
                            <input type="text" class="form-control" name="state" data-contact="StateOrRegion" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Postal code</label>
                            <input type="text" class="form-control" name="postal_code" data-contact="PostalCode" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone number</label>
                            <input type="text" class="form-control" name="phone_number" data-contact="PhoneNumber" placeholder="+1 555 0100" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Country</label>
                            <select class="form-select" name="country" data-contact="CountryCode" required>
                                <option value="">Select country</option>
                                <?php foreach (countries() as $code => $name): ?>
                                    <option value="<?php echo h($code); ?>"><?php echo h($name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div id="baseIpMessage" class="small text-muted mt-3">
                        Loading current member account primary contact details...
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-info" id="submitBaseIpBtn">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const configureUrl = "configure.php?ac_id=<?php echo rawurlencode($child_id); ?>&parent_id=<?php echo rawurlencode($parent_id); ?>";
        let otpStep = false;

        function escapeHtml(value) {
            return $("<div>").text(value).html();
        }

        function showMessage(target, success, message) {
            const alertClass = success ? "alert-success" : "alert-danger";
            $(target).html("<div class='alert " + alertClass + " mb-0'>" + escapeHtml(message) + "</div>");
        }

        function contextData(action) {
            return {
                action: action,
                child_account_id: <?php echo json_encode($child_id); ?>,
                parent_account_id: <?php echo json_encode($parent_id); ?>
            };
        }

        $("#attachSecretPolicyBtn").on("click", function() {
            const btn = $(this);
            btn.prop("disabled", true).text("Attaching...");
            showMessage("#response", true, "Attaching Policy Account Configuration...");

            $.post(configureUrl, contextData("attach_secret_policy"), function(data) {
                showMessage("#response", data.success, data.message);
            }, "json").fail(function(xhr) {
                showMessage("#response", false, xhr.responseText || xhr.statusText || "Server error.");
            }).always(function() {
                btn.prop("disabled", false).text("Attach Secret Policy");
            });
        });

        $("#updateEmailModal").on("hidden.bs.modal", function() {
            otpStep = false;
            $("#updateEmailForm")[0].reset();
            $("#otpGroup").addClass("d-none");
            $("#newEmail").prop("readonly", false);
            $("#otp").prop("required", false);
            $("#submitEmailBtn").prop("disabled", false).text("Send OTP");
            $("#modalMessage").removeClass("text-danger text-success").addClass("text-muted")
                .text("Enter the new email address to receive the AWS verification OTP.");
        });

        $("#updateEmailForm").on("submit", function(e) {
            e.preventDefault();

            const newEmail = $("#newEmail").val().trim();
            const otp = $("#otp").val().trim();
            const action = otpStep ? "verify_email_otp" : "send_email_otp";

            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(newEmail)) {
                $("#modalMessage").removeClass("text-muted text-success").addClass("text-danger").text("Enter a valid email address.");
                return;
            }

            if (otpStep && !/^[A-Za-z0-9]{6}$/.test(otp)) {
                $("#modalMessage").removeClass("text-muted text-success").addClass("text-danger").text("Enter the 6-character OTP from the AWS email.");
                return;
            }

            $("#submitEmailBtn").prop("disabled", true).text(otpStep ? "Verifying..." : "Sending...");
            $("#modalMessage").removeClass("text-danger text-success").addClass("text-muted")
                .text(otpStep ? "Verifying OTP with AWS..." : "Requesting AWS email verification OTP...");

            $.post(configureUrl, $(this).serialize() + "&action=" + encodeURIComponent(action), function(data) {
                if (data.success && !otpStep) {
                    otpStep = true;
                    $("#otpGroup").removeClass("d-none");
                    $("#otp").prop("required", true).focus();
                    $("#newEmail").prop("readonly", true);
                    $("#submitEmailBtn").prop("disabled", false).text("Verify OTP");
                    $("#modalMessage").removeClass("text-muted text-danger").addClass("text-success").text(data.message);
                    showMessage("#response", true, data.message);
                    return;
                }

                if (data.success) {
                    $("#submitEmailBtn").prop("disabled", true).text("Updated");
                    $("#modalMessage").removeClass("text-muted text-danger").addClass("text-success").text(data.message);
                    showMessage("#response", true, data.message);
                    return;
                }

                $("#submitEmailBtn").prop("disabled", false).text(otpStep ? "Verify OTP" : "Send OTP");
                $("#modalMessage").removeClass("text-muted text-success").addClass("text-danger").text(data.message);
                showMessage("#response", false, data.message);
            }, "json").fail(function(xhr) {
                const message = xhr.responseText || xhr.statusText || "Server error.";
                $("#submitEmailBtn").prop("disabled", false).text(otpStep ? "Verify OTP" : "Send OTP");
                $("#modalMessage").removeClass("text-muted text-success").addClass("text-danger").text(message);
                showMessage("#response", false, message);
            });
        });

        $("#updateBaseIpModal").on("show.bs.modal", function() {
            $("#submitBaseIpBtn").prop("disabled", true);
            $("#baseIpMessage").removeClass("text-danger text-success").addClass("text-muted")
                .text("Loading current member account primary contact details...");

            $.post(configureUrl, contextData("get_contact"), function(data) {
                if (!data.success) {
                    $("#baseIpMessage").removeClass("text-muted text-success").addClass("text-danger").text(data.message);
                    showMessage("#response", false, data.message);
                    return;
                }

                const contact = data.contact || {};
                $("#updateBaseIpForm [data-contact]").each(function() {
                    $(this).val(contact[$(this).data("contact")] || "");
                });
                $("#baseIpMessage").removeClass("text-muted text-danger").addClass("text-success").text(data.message);
                $("#submitBaseIpBtn").prop("disabled", false);
            }, "json").fail(function(xhr) {
                const message = xhr.responseText || xhr.statusText || "Server error.";
                $("#baseIpMessage").removeClass("text-muted text-success").addClass("text-danger").text(message);
                showMessage("#response", false, message);
            });
        });

        $("#updateBaseIpForm").on("submit", function(e) {
            e.preventDefault();

            $("#submitBaseIpBtn").prop("disabled", true).text("Saving...");
            $("#baseIpMessage").removeClass("text-danger text-success").addClass("text-muted")
                .text("Updating member account primary contact details...");

            $.post(configureUrl, $(this).serialize() + "&action=update_contact", function(data) {
                $("#baseIpMessage").removeClass("text-muted text-danger text-success")
                    .addClass(data.success ? "text-success" : "text-danger").text(data.message);
                showMessage("#response", data.success, data.message);
            }, "json").fail(function(xhr) {
                const message = xhr.responseText || xhr.statusText || "Server error.";
                $("#baseIpMessage").removeClass("text-muted text-success").addClass("text-danger").text(message);
                showMessage("#response", false, message);
            }).always(function() {
                $("#submitBaseIpBtn").prop("disabled", false).text("Save");
            });
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
