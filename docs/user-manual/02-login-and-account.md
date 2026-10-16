# 2. Login and account security

## Sign in

1. Open the business root page `/`. If signed out, it shows a welcome page with the business/app name and **Log in**. If already signed in, it redirects to **Dashboard**.
2. Select **Log in**.
3. Enter **Email address** and **Password**. Select **Remember me** only on a trusted device.
4. Select **Log in**. If two-factor authentication is enabled, complete the subsequent challenge.

Use **Forgot your password?** on the login form to request a password reset when enabled. Follow the reset link sent to the account email. New user invitations also use the password setup/reset flow; delivery depends on the configured mail service.

## First business setup

The welcome/login pages show **Set up business** or **Sign up** only while no business exists. Registration is not a general staff-invitation workflow after the first business is created; authorized managers invite users through **User Management → Invite user**.

## Change your password and security

1. Open **Settings → Security**.
2. Enter **Current password**, **New password**, and **Confirm password**.
3. Select **Save changes**. Correct validation errors and try again.

The security page also contains the account's passkey and two-factor management controls where supported. Follow the on-screen confirmation and recovery-code instructions; keep recovery codes private. Password updates are throttled after repeated attempts.

## Sign out

Open the user menu in the application header and choose the sign-out action. The authenticated session ends and the user is returned to the login flow. Sign out on shared devices.

## Troubleshooting

- **Invalid credentials:** check the email spelling and password; use the reset link if necessary.
- **Page denied or menu missing:** ask an administrator to review the user's role and location assignments.
- **Invitation/reset email missing:** verify the target email and contact the business administrator; mail delivery must be configured by the deployment operator.
