<?php
// inc/FawaterkPayment.php
// Handles Fawaterk payment gateway integration for course purchases

class FawaterkPayment
{
    private $conn;
    private $apiKey;
    private $endpoint;

    public function __construct($conn, $apiKey, $endpoint = 'https://app.fawaterk.com/api/v2/createInvoiceLink')
    {
        $this->conn = $conn;
        $this->apiKey = $apiKey;
        $this->endpoint = $endpoint;
    }

    /**
     * Initiate payment for a course purchase
     * @param int $user_id
     * @param int $course_id
     * @return array
     */
    public function payForCourse($user_id, $course_id)
    {
        // Fetch user info
        $userStmt = $this->conn->prepare('SELECT * FROM users WHERE user_id = ? LIMIT 1');
        $userStmt->bind_param('i', $user_id);
        $userStmt->execute();
        $user = $userStmt->get_result()->fetch_assoc();
        $userStmt->close();
        if (!$user) {
            return ['status' => 0, 'message' => 'User not found'];
        }

        // Fetch course info
        $courseStmt = $this->conn->prepare('SELECT * FROM courses WHERE course_id = ? LIMIT 1');
        $courseStmt->bind_param('i', $course_id);
        $courseStmt->execute();
        $course = $courseStmt->get_result()->fetch_assoc();
        $courseStmt->close();
        if (!$course) {
            return ['status' => 0, 'message' => 'Course not found'];
        }

        // Prepare invoice data
        $invoiceData = [
            'cartTotal' => (string) $course['course_price'],
            'currency' => 'EGP',
            'customer' => [
                'first_name' => explode(' ', $user['username'])[0] ?? '',
                'last_name' => explode(' ', $user['username'], 2)[1] ?? '',
                'email' => $user['email'] ?? '',
                'phone' => $user['phone'] ?? '',
                'address' => $user['address'] ?? '',
            ],
            'redirectionUrls' => [
                'successUrl' => $this->getBaseUrl() . '/payment/success',
                'failUrl' => $this->getBaseUrl() . '/payment/fail',
                'pendingUrl' => $this->getBaseUrl() . '/payment/pending',
            ],
            'cartItems' => [
                [
                    'name' => $course['course_title'],
                    'price' => (string) $course['course_price'],
                    'quantity' => '1',
                ]
            ],
        ];

        // Call Fawaterk API
        $response = $this->callFawaterkApi($invoiceData);
        if ($response['status'] && isset($response['data']['url'])) {
            // Optionally: Save invoice info to DB here
            return ['status' => 1, 'payment_url' => $response['data']['url']];
        } else {
            return ['status' => 0, 'message' => 'Error creating invoice', 'error' => $response['error'] ?? ''];
        }
    }

    private function callFawaterkApi($invoiceData)
    {
        $ch = curl_init($this->endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($invoiceData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ]);
        $result = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($error) {
            return ['status' => 0, 'error' => $error];
        }
        $data = json_decode($result, true);
        if ($httpCode === 200 && isset($data['data']['url'])) {
            return ['status' => 1, 'data' => $data['data']];
        } else {
            return ['status' => 0, 'error' => $data['message'] ?? 'Unknown error'];
        }
    }

    private function getBaseUrl()
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $script = dirname($_SERVER['SCRIPT_NAME']);
        return rtrim("$protocol://$host$script", '/');
    }
}

// Usage example (for AJAX endpoint):
// require_once '../connection/config.php';
// require_once 'FawaterkPayment.php';
// $conn = db();
// $apiKey = 'YOUR_FAWATERK_API_KEY';
// $fawaterk = new FawaterkPayment($conn, $apiKey);
// $result = $fawaterk->payForCourse($user_id, $course_id);
// echo json_encode($result);
