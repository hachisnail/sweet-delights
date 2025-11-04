<?php
namespace SweetDelights\Mayie\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Slim\Views\Twig; // To render the email template
class MailService
{
    private $mailer;
    private $twig;

    public function __construct(Twig $twig)
    {
        $this->twig = $twig;
        $this->mailer = new PHPMailer(true);

        try {
            // Server settings
            $this->mailer->isSMTP();
            $this->mailer->Host       = $_ENV['MAIL_HOST'];
            $this->mailer->SMTPAuth   = (bool)$_ENV['MAIL_SMTPAuth'];
            $this->mailer->Username   = $_ENV['MAIL_USERNAME'];
            $this->mailer->Password   = $_ENV['MAIL_PASSWORD'];
            $this->mailer->SMTPSecure = $_ENV['MAIL_SMTPSECURE'];
            $this->mailer->Port       = (int)$_ENV['MAIL_PORT'];

            // Sender
            $this->mailer->setFrom($_ENV['MAIL_FROM_ADDRESS'], $_ENV['MAIL_FROM_NAME']);

        } catch (Exception $e) {
            // Handle error, e.g., log it
            error_log("PHPMailer configuration error: {$this->mailer->ErrorInfo}");
        }
    }

    public function sendVerificationEmail(array $user, string $token)
    {
        try {
            // Recipient
            $this->mailer->addAddress($user['email'], $user['first_name'] . ' ' . $user['last_name']);

            // --- Content ---
            $this->mailer->isHTML(true);
            $this->mailer->Subject = 'Verify Your FlourEver Account';

            // Create the verification link
            $verificationLink = $_ENV['APP_URL'] . '/verify-email?token=' . $token;

            // Render the email body from a Twig template
            $emailBody = $this->twig->fetch('Email/verification.twig', [
                'user_name' => $user['first_name'],
                'verification_link' => $verificationLink
            ]);

            $this->mailer->Body    = $emailBody;
            $this->mailer->AltBody = 'Please verify your account by clicking this link: ' . $verificationLink;

            $this->mailer->send();
            return true;
        } catch (Exception $e) {
            error_log("Message could not be sent. Mailer Error: {$this->mailer->ErrorInfo}");
            return false;
        }
    }


      public function sendPasswordResetEmail(array $user, string $token)
    {
        try {
            // Recipient
            $this->mailer->addAddress($user['email'], $user['first_name'] . ' ' . $user['last_name']);

            // --- Content ---
            $this->mailer->isHTML(true);
            $this->mailer->Subject = 'Your FlourEver Password Reset Request';

            $resetLink = $_ENV['APP_URL'] . '/reset-password?token=' . $token;

            $emailBody = $this->twig->fetch('Email/reset-password.twig', [
                'user_name' => $user['first_name'],
                'reset_link' => $resetLink
            ]);

            $this->mailer->Body    = $emailBody;
            $this->mailer->AltBody = 'Please reset your password by clicking this link: ' . $resetLink;

            $this->mailer->send();
            return true;
        } catch (Exception $e) {
            error_log("Message could not be sent. Mailer Error: {$this->mailer->ErrorInfo}");
            return false;
        }
    }

}