{{--
  Public privacy policy for the mobile loyalty app ("The Coffee Bean & Tea Leaf
  Rewards", shown on device as "CBTL").

  Required by both stores: App Store Connect and Google Play each demand a
  publicly reachable, login-free privacy policy URL. Like /account-deletion this
  is a plain Blade view rather than an Inertia page, so reviewers and crawlers
  can read it with no JS and no built assets.

  The document below is wrapped in Blade's verbatim directive: it is entirely
  static, and its CSS is full of at-rules that Blade should never inspect.
--}}
@verbatim
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Privacy Policy for the CBTL mobile application operated by Table Group Inc.">
  <title>Privacy Policy | The Coffee Bean &amp; Tea Leaf®</title>

  <!-- CBTL Official Web Fonts: Recoleta Alt & Avenir Next Cyr -->
  <style>
    @font-face {
      font-family: "Recoleta Alt";
      font-style: normal;
      font-weight: 400;
      src: local("Recoleta Alt Regular"),
           url("https://www.coffeebean.com.ph/wp-content/uploads/2025/07/Recoleta-Alt-Regular.woff2") format("woff2");
      font-display: swap;
    }
    @font-face {
      font-family: "Recoleta Alt";
      font-style: normal;
      font-weight: 500;
      src: local("Recoleta Alt Medium"),
           url("https://www.coffeebean.com.ph/wp-content/uploads/2025/07/recoleta-alt-medium.woff2") format("woff2");
      font-display: swap;
    }
    @font-face {
      font-family: "Recoleta Alt";
      font-style: normal;
      font-weight: 600;
      src: local("Recoleta Alt Semi Bold"),
           url("https://www.coffeebean.com.ph/wp-content/uploads/2025/07/fonnts.com-recoletaalt-semibold.woff2") format("woff2");
      font-display: swap;
    }
    @font-face {
      font-family: "Recoleta Alt";
      font-style: normal;
      font-weight: 700;
      src: local("Recoleta Alt Bold"),
           url("https://www.coffeebean.com.ph/wp-content/uploads/2025/08/Recoleta-Alt-Bold.woff2") format("woff2");
      font-display: swap;
    }
    @font-face {
      font-family: "Avenir Next Cyr";
      font-style: normal;
      font-weight: 400;
      src: local("Avenir Next Cyr Regular"),
           url("https://www.coffeebean.com.ph/wp-content/uploads/2025/07/AvenirNextCyr-Regular.woff2") format("woff2");
      font-display: swap;
    }
    @font-face {
      font-family: "Avenir Next Cyr";
      font-style: normal;
      font-weight: 500;
      src: local("Avenir Next Cyr Medium"),
           url("https://www.coffeebean.com.ph/wp-content/uploads/2025/07/AvenirNextCyr-Medium.woff2") format("woff2");
      font-display: swap;
    }
    @font-face {
      font-family: "Avenir Next Cyr";
      font-style: normal;
      font-weight: 600;
      src: local("Avenir Next Cyr Demi Bold"),
           url("https://www.coffeebean.com.ph/wp-content/uploads/2025/07/AvenirNextCyr-Demi.woff2") format("woff2");
      font-display: swap;
    }
    @font-face {
      font-family: "Avenir Next Cyr";
      font-style: normal;
      font-weight: 700;
      src: local("Avenir Next Cyr Bold"),
           url("https://www.coffeebean.com.ph/wp-content/uploads/2025/07/AvenirNextCyr-Bold.woff2") format("woff2");
      font-display: swap;
    }

    :root {
      --global-palette1: #181d27; /* Rich dark charcoal / almost-black */
      --global-palette2: #3a1454; /* Deep brand royal purple */
      --global-palette3: #000000;
      --global-palette4: #3a3b3e; /* Primary body copy color */
      --global-palette5: #64427c; /* Medium purple accent */
      --global-palette6: #532d6d; /* CBTL Signature Purple */
      --global-palette7: #e2e2e3; /* Soft divider border */
      --global-palette8: #eeeaf0; /* Warm lavender-gray page background */
      --global-palette9: #ffffff; /* Crisp white */
      --global-palette14: #f7630c; /* Accent orange highlight */

      --font-heading: "Recoleta Alt", Georgia, "Times New Roman", serif;
      --font-body: "Avenir Next Cyr", "Avenir Next", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      --max-width: 860px;
    }

    * {
      box-sizing: border-box;
    }

    html {
      scroll-behavior: smooth;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
    }

    body {
      margin: 0;
      background-color: var(--global-palette8);
      color: var(--global-palette4);
      font-family: var(--font-body);
      font-size: 16.5px;
      line-height: 1.7;
    }

    /* Masthead Navigation Bar */
    #masthead {
      background: #ffffff;
      border-bottom: 1px solid #f0edf2;
      position: sticky;
      top: 0;
      z-index: 1000;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03);
    }

    .nav-container {
      max-width: 1240px;
      margin: 0 auto;
      padding: 0 28px;
      height: 78px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .brand-logo {
      display: inline-flex;
      align-items: center;
      text-decoration: none;
    }

    .brand-logo img {
      height: 38px;
      width: auto;
      display: block;
    }

    .nav-links {
      display: flex;
      align-items: center;
      gap: 24px;
      list-style: none;
      margin: 0;
      padding: 0;
    }

    .nav-links a {
      color: #212225;
      text-decoration: none;
      font-size: 15.5px;
      font-weight: 500;
      transition: color 0.2s ease;
    }

    .nav-links a:hover,
    .nav-links a.active {
      color: var(--global-palette6);
    }

    .nav-btn {
      display: inline-block;
      background-color: var(--global-palette6);
      color: #ffffff !important;
      padding: 9px 20px;
      border-radius: 999px;
      font-weight: 600;
      font-size: 14px;
      transition: background-color 0.2s ease, transform 0.15s ease;
    }

    .nav-btn:hover {
      background-color: var(--global-palette2);
      transform: translateY(-1px);
    }

    /* Hero Banner Section with Official Shapes Pattern */
    .hero-banner {
      background-color: #f5f4f6;
      background-image: url('https://www.coffeebean.com.ph/wp-content/uploads/2025/07/shapes-header-banner-transparent-background-min.png');
      background-size: cover;
      background-position: center center;
      background-repeat: no-repeat;
      padding: clamp(52px, 7vw, 84px) 24px clamp(46px, 6vw, 76px);
      text-align: center;
      border-bottom: 1px solid #e7e3ea;
    }

    .hero-inner {
      max-width: var(--max-width);
      margin: 0 auto;
    }

    .eyebrow-tag {
      margin: 0 0 10px;
      color: var(--global-palette6);
      font-size: 0.95rem;
      font-weight: 600;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }

    h1 {
      margin: 0 0 14px;
      font-family: var(--font-heading);
      font-size: clamp(2.4rem, 5.5vw, 4rem);
      font-weight: 700;
      color: var(--global-palette1);
      line-height: 1.15;
      letter-spacing: -0.015em;
    }

    .hero-subtitle {
      margin: 0 0 8px;
      font-size: 1.05rem;
      font-weight: 500;
      color: var(--global-palette5);
    }

    .effective-date {
      margin: 0;
      color: #717680;
      font-size: 0.92rem;
      font-weight: 400;
    }

    /* Main Content Container */
    main {
      width: min(100% - 36px, var(--max-width));
      margin: 44px auto 72px;
      background: var(--global-palette9);
      border-radius: 18px;
      padding: clamp(28px, 5.5vw, 62px);
      border: 1px solid #ded6d0;
      box-shadow: 0 15px 30px -10px rgba(83, 45, 109, 0.06), 0 2px 8px rgba(0, 0, 0, 0.02);
    }

    /* Summary Callout Banner */
    .summary {
      margin: 0 0 38px;
      padding: 22px 24px;
      background: #fbf9fc;
      border-left: 4px solid var(--global-palette6);
      border-radius: 0 12px 12px 0;
      color: var(--global-palette2);
      font-size: 1.04rem;
      line-height: 1.7;
    }

    h2 {
      margin: 42px 0 14px;
      font-family: var(--font-heading);
      font-size: 1.62rem;
      font-weight: 700;
      color: var(--global-palette1);
      line-height: 1.3;
      padding-bottom: 6px;
      border-bottom: 1px solid #f0edf2;
    }

    h2:first-of-type {
      margin-top: 0;
    }

    p, li {
      text-wrap: pretty;
      color: var(--global-palette4);
    }

    p {
      margin: 0 0 16px;
    }

    p strong {
      color: var(--global-palette1);
    }

    ul {
      margin: 0 0 22px;
      padding-left: 24px;
    }

    li {
      margin-bottom: 10px;
    }

    li strong {
      color: var(--global-palette1);
    }

    a {
      color: var(--global-palette6);
      font-weight: 600;
      text-decoration: underline;
      text-underline-offset: 3px;
      transition: color 0.18s ease;
    }

    a:hover {
      color: var(--global-palette2);
    }

    .contact {
      margin-top: 20px;
      padding: 24px 26px;
      background: #faf8fb;
      border: 1px solid #e7e0ed;
      border-radius: 12px;
    }

    .contact p {
      margin: 6px 0;
    }

    /* CBTL Signature Footer with Rounded Purple Arc */
    .cbtl-footer {
      background-color: var(--global-palette6);
      border-top-left-radius: 50px;
      border-top-right-radius: 50px;
      color: #ffffff;
      padding: 60px 24px 36px;
      margin-top: 60px;
    }

    .footer-inner {
      max-width: 1140px;
      margin: 0 auto;
    }

    .footer-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 28px;
      padding-bottom: 38px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.15);
    }

    .footer-logo img {
      max-width: 240px;
      height: auto;
      display: block;
    }

    .footer-nav {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 22px;
      list-style: none;
      margin: 0;
      padding: 0;
    }

    .footer-nav a {
      color: #ffffff;
      font-size: 15px;
      font-weight: 500;
      text-decoration: none;
      transition: opacity 0.2s ease;
    }

    .footer-nav a:hover {
      opacity: 0.85;
      text-decoration: underline;
    }

    .footer-bottom {
      padding-top: 28px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 18px;
      font-size: 0.88rem;
      color: #e2d7eb;
    }

    .footer-bottom a {
      color: #ffffff;
      text-decoration: underline;
      font-weight: 400;
    }

    .footer-socials {
      display: flex;
      align-items: center;
      gap: 14px;
    }

    .social-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.12);
      color: #ffffff;
      text-decoration: none;
      transition: background 0.2s ease, transform 0.15s ease;
    }

    .social-btn:hover {
      background: rgba(255, 255, 255, 0.24);
      transform: translateY(-2px);
      color: #ffffff;
    }

    .social-btn svg {
      width: 17px;
      height: 17px;
      fill: currentColor;
    }

    /* Responsive Adaptations */
    @media (max-width: 820px) {
      .nav-links {
        display: none;
      }

      .footer-top {
        flex-direction: column;
        align-items: flex-start;
      }

      .footer-bottom {
        flex-direction: column;
        align-items: flex-start;
      }
    }

    @media (max-width: 560px) {
      .hero-banner {
        padding: 42px 18px 36px;
      }

      main {
        width: 100%;
        margin-top: 0;
        margin-bottom: 40px;
        border-radius: 0;
        border-inline: 0;
        box-shadow: none;
        padding: 24px 18px;
      }

      .cbtl-footer {
        border-top-left-radius: 32px;
        border-top-right-radius: 32px;
        padding: 42px 18px 28px;
      }
    }

    /* Print Stylesheet */
    @media print {
      body {
        background: #ffffff;
        color: #000000;
      }

      #masthead, .cbtl-footer {
        display: none;
      }

      .hero-banner {
        background: none;
        padding: 20px 0;
        border: 0;
      }

      main {
        border: none;
        box-shadow: none;
        padding: 0;
        margin: 0;
        width: 100%;
      }

      a {
        color: #000000;
        text-decoration: underline;
      }
    }
  </style>
</head>
<body>

  <!-- CBTL Navigation Bar -->
  <header id="masthead">
    <div class="nav-container">
      <a href="https://www.coffeebean.com.ph/" class="brand-logo" title="The Coffee Bean &amp; Tea Leaf®">
        <img src="https://www.coffeebean.com.ph/wp-content/uploads/2025/07/Logo.png" alt="The Coffee Bean &amp; Tea Leaf®" width="220" height="21">
      </a>
      <ul class="nav-links">
        <li><a href="https://www.coffeebean.com.ph/menu/">Menu</a></li>
        <li><a href="https://www.coffeebean.com.ph/store-directory/">Find a Store</a></li>
        <li><a href="https://www.coffeebean.com.ph/whats-new/">What’s New?</a></li>
        <li><a href="https://www.coffeebean.com.ph/our-brand/">Our Brand</a></li>
        <li><a href="https://www.coffeebean.com.ph/caring-cup/">Caring Cup</a></li>
        <li><a href="https://www.coffeebean.com.ph/swirl-rewards/">Swirl Rewards</a></li>
        <li><a href="https://support.tablegroup.com.ph/account-deletion" class="nav-btn">Account Support</a></li>
      </ul>
    </div>
  </header>

  <!-- Hero Banner matching coffeebean.com.ph/terms-of-use/ -->
  <section class="hero-banner">
    <div class="hero-inner">
      <p class="eyebrow-tag">The Fine Print – Made Simple</p>
      <h1>Privacy Policy</h1>
      <p class="hero-subtitle">Coffee Bean &amp; Tea Leaf Rewards &middot; CBTL App</p>
      <p class="effective-date">Effective and last updated: September 10, 2026</p>
    </div>
  </section>

  <!-- Main Legal Document Content -->
  <main>
    <div class="summary">
      This Privacy Policy explains how Table Group Inc. ("Table Group," "we," "us," or "our") collects, uses, stores, and protects information when you use the Coffee Bean &amp; Tea Leaf Rewards mobile application, which appears on your device as "CBTL" (the "App").
    </div>

    <h2>1. Information We Collect</h2>
    <p>Depending on how you use the App, we collect or process the following categories of information:</p>
    <ul>
      <li><strong>Account and profile information:</strong> information you provide when registering or signing in: your name, email address, a password (which we store only in hashed form), and, if you choose to provide it, your mobile number. We also assign a member identifier to your account.</li>
      <li><strong>Authentication information:</strong> information required to verify your identity and keep you signed in, including session tokens, one-time verification codes, the key used by an authenticator app if you choose to set one up, and records of sign-in attempts. We do not receive or store your fingerprint or facial-recognition data.</li>
      <li><strong>Loyalty information:</strong> the campaigns you take part in, your stamp cards and their progress, the stamps you earn and the rewards you redeem, and related transaction history, including when and at which store a stamp or reward was processed.</li>
      <li><strong>Member and redemption codes:</strong> the App displays QR codes that identify your account or a specific stamp card. When store staff scan one of these codes, the scan is recorded against your account.</li>
      <li><strong>Device information:</strong> your device's manufacturer and model name (for example, "Samsung SM-G991B"), sent when you sign in so that your session can be labelled on our systems. The App does not collect advertising IDs or other unique device identifiers.</li>
      <li><strong>Server records:</strong> like most online services, our servers may record technical details of the requests they receive, such as IP address and time, for security and troubleshooting.</li>
      <li><strong>Information stored on your device:</strong> the App keeps a copy of your account, session, and loyalty information on your device so that it works offline, including your member code. Security-sensitive items, such as your session token, are kept in your device's encrypted secure storage.</li>
    </ul>
    <p><strong>What we do not collect.</strong> The App does not collect your location, and does not access your camera, microphone, photos, contacts, or files. It contains no advertising or third-party analytics tools.</p>

    <h2>2. How We Use Information</h2>
    <p>We use information as reasonably necessary to:</p>
    <ul>
      <li>create and manage accounts and authenticate users;</li>
      <li>provide loyalty campaigns, stamps, cards, rewards, redemption, and transaction-history features;</li>
      <li>display your member and redemption codes so store staff can scan them;</li>
      <li>maintain app security, prevent fraud or misuse, and troubleshoot technical issues;</li>
      <li>support offline functionality and synchronize your loyalty information with our systems;</li>
      <li>respond to support, privacy, or account-related requests; and</li>
      <li>comply with applicable laws and enforce our terms and policies.</li>
    </ul>

    <h2>3. App Permissions</h2>
    <p>The App requests only the permissions it needs to function: <strong>internet and network-state access</strong>, to communicate with our servers and detect when you are offline; and <strong>biometric authentication</strong>, if you choose to unlock the App with your fingerprint or face.</p>
    <p>The App does not request access to your location, camera, microphone, photos, contacts, or files.</p>
    <p>When biometric authentication is enabled, verification is performed by your device's operating system. The App receives only the authentication result and does not receive or store your biometric template.</p>

    <h2>4. Sharing and Disclosure</h2>
    <p>We do not sell your personal information, and we do not share it with advertisers or data brokers. We may disclose information only as reasonably necessary:</p>
    <ul>
      <li>to service providers that help us operate, host, secure, or support the App, acting on our behalf;</li>
      <li>within Table Group Inc. and with authorized personnel, including store staff, who need the information for legitimate business purposes such as issuing stamps and redeeming rewards;</li>
      <li>to comply with a legal obligation, lawful request, court order, or regulatory requirement; or</li>
      <li>to protect users, Table Group Inc., or others against fraud, security threats, or harm.</li>
    </ul>
    <p>Service providers are expected to process information only for authorized purposes and subject to appropriate confidentiality and security obligations.</p>

    <h2>5. Data Retention</h2>
    <p>We retain personal information only for as long as reasonably necessary to provide the App, maintain required business or transaction records, comply with legal obligations, resolve disputes, and protect against fraud or misuse. Retention periods may differ depending on the type of information and the reason it is processed.</p>
    <p>When you ask us to delete your account, we first close it and then permanently delete it after a waiting period. Redeemed rewards are financial records, so an account that has redeemed a reward is closed but kept in a restricted archive rather than permanently deleted. What is deleted and what is kept after you ask us to delete your account, and for how long, is set out on our <a href="https://support.tablegroup.com.ph/account-deletion">Account Deletion page</a>.</p>

    <h2>6. Data Security</h2>
    <p>We use reasonable administrative, technical, and organizational safeguards designed to protect information against unauthorized access, loss, misuse, alteration, or disclosure. All communication between the App and our servers is encrypted in transit (HTTPS). Passwords are stored only in hashed form, and session tokens are kept in your device's encrypted secure storage. No storage or transmission method is completely secure, so absolute security cannot be guaranteed.</p>

    <h2 id="delete">7. Your Choices and Rights</h2>
    <p>You may manage the biometric-unlock setting in the App, sign out, or uninstall the App. Subject to applicable law, you may also request access to, correction of, or deletion of your personal information, or object to or restrict certain processing.</p>
    <p>Users in the Philippines may have rights under the Data Privacy Act of 2012 (Republic Act No. 10173) and its implementing rules. We may need to verify your identity before completing a request.</p>
    <p><strong>Deleting your account.</strong> You can ask us to delete your account and associated data at any time, free of charge. Follow the steps on our <a href="https://support.tablegroup.com.ph/account-deletion">Account Deletion page</a>, or email <a href="mailto:tasservices@tablegroup.com.ph?subject=Account%20Deletion%20Request">tasservices@tablegroup.com.ph</a> from your registered email address with the subject line <em>Account Deletion Request</em>. Deleting your account permanently forfeits any unredeemed stamps and rewards.</p>
    <p>Deleting the App from your device does not delete your account. It does remove the copy of your information stored in the App. On iPhone and iPad, a few sign-in items held in the device&rsquo;s secure keychain may remain after the App is deleted; they stop working once your account is closed.</p>

    <h2>8. Children's Privacy</h2>
    <p>The App is not intended for children under 13, and we do not knowingly collect personal information from children under 13 without appropriate authorization. If you believe a child has provided personal information through the App, please contact us so we can review and address the matter.</p>

    <h2>9. Third-Party Services and Links</h2>
    <p>The App is distributed through Google Play and Apple&rsquo;s App Store, which process information about app downloads and updates under their own privacy policies, available at <a href="https://policies.google.com/privacy" rel="noopener noreferrer">policies.google.com/privacy</a> and <a href="https://www.apple.com/legal/privacy/" rel="noopener noreferrer">apple.com/legal/privacy</a>. The App may also link to third-party services, which process information under their own privacy policies.</p>

    <h2>10. Changes to This Policy</h2>
    <p>We may update this Privacy Policy to reflect changes to the App, our practices, or applicable requirements. We will post the revised policy at this location and update the effective date above. Material changes may also be communicated through the App or another appropriate channel.</p>

    <h2>11. Contact Us</h2>
    <p>For questions, requests, or concerns relating to this Privacy Policy or our handling of personal information, contact:</p>
    <div class="contact">
      <p><strong>Table Group Inc.</strong></p>
      <p>Privacy questions: <a href="mailto:info@tablegroup.com.ph">info@tablegroup.com.ph</a> (subject line: CBTL Privacy Request)</p>
      <p>Account deletion: <a href="mailto:tasservices@tablegroup.com.ph?subject=Account%20Deletion%20Request">tasservices@tablegroup.com.ph</a> (subject line: Account Deletion Request)</p>
    </div>
  </main>

  <!-- CBTL Curved Signature Footer -->
  <footer class="cbtl-footer">
    <div class="footer-inner">
      <div class="footer-top">
        <a href="https://www.coffeebean.com.ph/" class="footer-logo">
          <img src="https://www.coffeebean.com.ph/wp-content/uploads/2025/07/coffee-bean-logo-white-min.png" alt="The Coffee Bean &amp; Tea Leaf®" width="220" height="35">
        </a>
        <ul class="footer-nav">
          <li><a href="https://www.coffeebean.com.ph/menu/">Menu</a></li>
          <li><a href="https://www.coffeebean.com.ph/store-directory/">Stores</a></li>
          <li><a href="https://www.coffeebean.com.ph/contact-us/">Contact Us</a></li>
          <li><a href="https://www.coffeebean.com.ph/careers/">Careers</a></li>
          <li><a href="https://www.coffeebean.com.ph/terms-of-use/">Terms of Use</a></li>
          <li><a href="https://www.coffeebean.com.ph/privacy-policy/">CBTL Website Privacy Policy</a></li>
        </ul>
      </div>

      <div class="footer-bottom">
        <div class="footer-copyright">
          &copy; 2026 Table Group Inc. &middot; “The Coffee Bean &amp; Tea Leaf” is a registered trademark of International Coffee &amp; Tea, LLC. All rights reserved.
        </div>
        <div class="footer-socials">
          <a href="https://www.facebook.com/coffeebeanphilippines" class="social-btn" target="_blank" rel="noopener noreferrer" aria-label="Facebook">
            <svg viewBox="0 0 512 512"><path d="M504 256C504 119 393 8 256 8S8 119 8 256c0 123.78 90.69 226.38 209.25 245V327.69h-63V256h63v-54.64c0-62.15 37-96.48 93.67-96.48 27.14 0 55.52 4.84 55.52 4.84v61h-31.28c-30.8 0-40.41 19.12-40.41 38.73V256h68.78l-11 71.69h-57.78V501C413.31 482.38 504 379.78 504 256z"/></svg>
          </a>
          <a href="https://www.instagram.com/cbtlph/" class="social-btn" target="_blank" rel="noopener noreferrer" aria-label="Instagram">
            <svg viewBox="0 0 448 512"><path d="M224.1 141c-63.6 0-114.9 51.3-114.9 114.9s51.3 114.9 114.9 114.9S339 319.5 339 255.9 287.7 141 224.1 141zm0 189.6c-41.1 0-74.7-33.5-74.7-74.7s33.5-74.7 74.7-74.7 74.7 33.5 74.7 74.7-33.6 74.7-74.7 74.7zm146.4-194.3c0 14.9-12 26.8-26.8 26.8-14.9 0-26.8-12-26.8-26.8s12-26.8 26.8-26.8 26.8 12 26.8 26.8zm76.1 27.2c-1.7-35.9-9.9-67.7-36.2-93.9-26.2-26.2-58-34.4-93.9-36.2-37-2.1-147.9-2.1-184.9 0-35.8 1.7-67.6 9.9-93.9 36.1s-34.4 58-36.2 93.9c-2.1 37-2.1 147.9 0 184.9 1.7 35.9 9.9 67.7 36.2 93.9s58 34.4 93.9 36.2c37 2.1 147.9 2.1 184.9 0 35.9-1.7 67.7-9.9 93.9-36.2 26.2-26.2 34.4-58 36.2-93.9 2.1-37 2.1-147.8 0-184.8zM398.8 388c-7.8 19.6-22.9 34.7-42.6 42.6-29.5 11.7-99.5 9-132.1 9s-102.7 2.6-132.1-9c-19.6-7.8-34.7-22.9-42.6-42.6-11.7-29.5-9-99.5-9-132.1s-2.6-102.7 9-132.1c7.8-19.6 22.9-34.7 42.6-42.6 29.5-11.7 99.5-9 132.1-9s102.7-2.6 132.1 9c19.6 7.8 34.7 22.9 42.6 42.6 11.7 29.5 9 99.5 9 132.1s2.7 102.7-9 132.1z"/></svg>
          </a>
          <a href="https://x.com/cbtlph" class="social-btn" target="_blank" rel="noopener noreferrer" aria-label="X">
            <svg viewBox="0 0 1200 1227"><path d="M714.163 519.284L1160.89 0H1055.03L667.137 450.887L357.328 0H0L468.492 681.821L0 1226.37H105.866L515.491 750.218L842.672 1226.37H1200L714.137 519.284H714.163ZM569.165 687.828L521.697 619.934L144.011 79.6944H306.615L611.412 515.685L658.88 583.579L1055.08 1150.3H892.476L569.165 687.854V687.828Z"/></svg>
          </a>
          <a href="http://www.youtube.com/@CBTLph" class="social-btn" target="_blank" rel="noopener noreferrer" aria-label="YouTube">
            <svg viewBox="0 0 576 512"><path d="M549.655 124.083c-6.281-23.65-24.787-42.276-48.284-48.597C458.781 64 288 64 288 64S117.22 64 74.629 75.486c-23.497 6.322-42.003 24.947-48.284 48.597-11.412 42.867-11.412 132.305-11.412 132.305s0 89.438 11.412 132.305c6.281 23.65 24.787 41.5 48.284 47.821C117.22 448 288 448 288 448s170.78 0 213.371-11.486c23.497-6.321 42.003-24.171 48.284-47.821 11.412-42.867 11.412-132.305 11.412-132.305s0-89.438-11.412-132.305zm-317.51 213.508V175.185l142.739 81.205-142.739 81.201z"/></svg>
          </a>
        </div>
      </div>
    </div>
  </footer>

</body>
</html>
@endverbatim
