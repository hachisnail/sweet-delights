<?php
namespace SweetDelights\Mayie\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class AccountController {

    /**
     * Show the user's account settings page.
     * It now also checks for query params to show success/error messages.
     */
    public function showSettings(Request $request, Response $response): Response {
        $view = Twig::fromRequest($request);
        $user = $request->getAttribute('user');
        $params = $request->getQueryParams();

        return $view->render($response, 'Public/account-settings.twig', [
            'title' => 'Account Settings',
            'user'  => $user,
            // Pass query params to the template for feedback
            'success' => $params['success'] ?? null,
            'error'   => $params['error'] ?? null
        ]);
    }

    /**
     * Show the user's order history page.
     * (This method is unchanged)
     */
    public function showOrders(Request $request, Response $response): Response {
        $view = Twig::fromRequest($request);

        // (Mock data and render logic is unchanged)
        $mockOrders = require __DIR__ . '/../Data/orders.php';
        ;
        return $view->render($response, 'Public/account-orders.twig', [
            'title' => 'My Orders',
            'orders' => $mockOrders
        ]);
    }

    /**
     * NEW: Handle the profile update form submission.
     */
    public function updateProfile(Request $request, Response $response): Response {
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody();
        
        $newName = $data['name'] ?? $user['name'];
        $newEmail = $data['email'] ?? $user['email'];

        // --- Mock "Save" Logic ---
        // In a real app, you'd save this to the database.
        // For this mock, we'll just update the session.
        $_SESSION['user']['name'] = $newName;
        $_SESSION['user']['email'] = $newEmail;
        // -------------------------

        // Redirect back with a success message
        return $response->withHeader('Location', '/account/settings?success=profile')->withStatus(302);
    }

    /**
     * NEW: Handle the change password form submission.
     */
    public function updatePassword(Request $request, Response $response): Response {
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody();

        $currentPassword = $data['current_password'] ?? '';
        $newPassword = $data['new_password'] ?? '';

        // 1. Load the user's real data from the "database"
        $users = require __DIR__ . '/../Data/users.php';
        $foundUser = null;
        foreach ($users as $u) {
            if ($u['id'] === $user['id']) {
                $foundUser = $u;
                break;
            }
        }

        // 2. Check if current password is correct
        if (!$foundUser || !password_verify($currentPassword, $foundUser['password_hash'])) {
            // Failed: Redirect back with an error
            return $response->withHeader('Location', '/account/settings?error=password')->withStatus(302);
        }

        // 3. Success: Password matches.
        // In a real app, you would hash $newPassword and save it.
        // We can't save to the file, but we can redirect with success.
        
        // --- Mock "Save" Logic ---
        // $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        // $db->execute("UPDATE users SET password_hash = ? WHERE id = ?", [$newHash, $user['id']]);
        // -------------------------

        // Redirect back with a success message
        return $response->withHeader('Location', '/account/settings?success=password')->withStatus(302);
    }
}

