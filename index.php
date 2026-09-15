<?php
session_start();
require "db.php";

$authError = "";
$mode = "signup";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $mode = $_POST["mode"] ?? "signup";
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($email === "" || $password === "") {
        $authError = "Email and password are required.";
    } elseif ($mode === "signup") {

        $name = trim($_POST["name"] ?? "");

        if ($name === "") {
            $authError = "Name is required.";
        } else {

            $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $check->bind_param("s", $email);
            $check->execute();
            $result = $check->get_result();

            if ($result->num_rows > 0) {
                $authError = "An account with this email already exists.";
            } else {

                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $conn->prepare(
                    "INSERT INTO users (name, email, password)
                     VALUES (?, ?, ?)"
                );

                $stmt->bind_param(
                    "sss",
                    $name,
                    $email,
                    $hashedPassword
                );

                if ($stmt->execute()) {

                    $_SESSION["user_id"] = $stmt->insert_id;
                    $_SESSION["user_name"] = $name;

                    header("Location: home.php");
                    exit();

                } else {
                    $authError = "Could not create account.";
                }

                $stmt->close();
            }

            $check->close();
        }

    } elseif ($mode === "login") {

        $stmt = $conn->prepare(
            "SELECT id, name, password
             FROM users
             WHERE email = ?"
        );

        $stmt->bind_param("s", $email);
        $stmt->execute();

        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if ($user && password_verify($password, $user["password"])) {

            $_SESSION["user_id"] = $user["id"];
            $_SESSION["user_name"] = $user["name"];

            header("Location: home.php");
            exit();

        } else {
            $authError = "Incorrect email or password.";
        }

        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Jobske</title>

    <link rel="stylesheet" href="style.css">
</head>

<body>
     <section class="hero-landing">

<header class="site-header">
    <div class="header-inner">
        <h1 class="logo">Jobske</h1>
   
        <a href="howitworks.php">How it works</a>
    </div>
</header>

<main>

 <section class="hero">

        <div class="hero-inner">

            <div class="hero-text">

                <h2>Connecting employers and job seekers</h2>

                <p>
                    Find opportunities, post jobs and connect with people.
                </p>

                <div class="hero-buttons">

                    <button
                        type="button"
                        class="btn btn-primary btn-large"
                        id="signupBtn">
                        Sign Up
                    </button>

                    <button
                        type="button"
                        class="btn btn-secondary btn-large"
                        id="loginBtn">
                        Log In
                    </button>

                </div>
                 <!-- ================= AUTH PANEL ================= -->

    <section
    class="auth-panel"
    id="authPanel"
    <?= $authError !== "" ? "" : "hidden" ?>>

        <div class="add-job-inner">

            <h2 id="authHeading">Sign Up</h2>

            <?php if ($authError !== ""): ?>

                <p class="auth-error">
                    <?= htmlspecialchars($authError) ?>
                </p>

            <?php endif; ?>


            <form
                method="POST"
                id="authForm">

                <input
                    type="hidden"
                    name="mode"
                    id="authMode"
                    value="<?= htmlspecialchars($mode) ?>">


                <div id="signupOnlyFields">

                    <label for="authName">
                        Name
                    </label>

                    <input
                        type="text"
                        id="authName"
                        name="name"
                        placeholder="Your name">

                </div>


                <label for="authEmail">
                    Email
                </label>

                <input
                    type="email"
                    id="authEmail"
                    name="email"
                    placeholder="you@example.com"
                    required>


                <label for="authPassword">
                    Password
                </label>

                          <div class="field-wrapper password-wrapper">
                    <input
                        type="password"
                        id="authPassword"
                        name="password"
                        placeholder="Password"
                        required>
                    <button type="button" class="password-toggle" id="passwordToggleBtn">👁</button>
                </div>


                <button
                    type="submit"
                    class="btn btn-primary"
                    id="authSubmitBtn">
                    Sign Up
                </button>

            </form>


            <p class="auth-toggle">

                <span id="authToggleText">
                    Already have an account?
                </span>

                <a href="#" id="authToggleLink">
                    Log in
                </a>

            </p>

        </div>

    </section>

            </div>

        </div>

    </section>
</section>

</main>


<script>

document.addEventListener("DOMContentLoaded", () => {

    const signupBtn =
        document.getElementById("signupBtn");

    const loginBtn =
        document.getElementById("loginBtn");

    const authPanel =
        document.getElementById("authPanel");

    const authHeading =
        document.getElementById("authHeading");

    const authMode =
        document.getElementById("authMode");

    const signupOnlyFields =
        document.getElementById("signupOnlyFields");

    const authSubmitBtn =
        document.getElementById("authSubmitBtn");

    const authToggleText =
        document.getElementById("authToggleText");

    const authToggleLink =
        document.getElementById("authToggleLink");


   let mode = "<?= htmlspecialchars($mode) ?>";


    function updateFormMode() {

        authMode.value = mode;

        if (mode === "signup") {

            authHeading.textContent = "Sign Up";

            authSubmitBtn.textContent = "Sign Up";

            signupOnlyFields.hidden = false;

            authToggleText.textContent =
                "Already have an account?";

            authToggleLink.textContent =
                "Log in";

        } else {

            authHeading.textContent = "Log In";

            authSubmitBtn.textContent = "Log In";

            signupOnlyFields.hidden = true;

            authToggleText.textContent =
                "Don't have an account?";

            authToggleLink.textContent =
                "Sign up";
        }
    }


    signupBtn.addEventListener("click", () => {

        mode = "signup";

        updateFormMode();

        authPanel.hidden = false;

        authPanel.scrollIntoView({
            behavior: "smooth"
        });

    });


    loginBtn.addEventListener("click", () => {

        mode = "login";

        updateFormMode();

        authPanel.hidden = false;

        authPanel.scrollIntoView({
            behavior: "smooth"
        });

    });


    authToggleLink.addEventListener("click", (e) => {

        e.preventDefault();

        mode =
            mode === "signup"
            ? "login"
            : "signup";

        updateFormMode();

    });


    updateFormMode();

        const authPasswordInput = document.getElementById("authPassword");
    const passwordToggleBtn = document.getElementById("passwordToggleBtn");

    if (passwordToggleBtn) {
        passwordToggleBtn.addEventListener("click", () => {
            const isHidden = authPasswordInput.type === "password";
            authPasswordInput.type = isHidden ? "text" : "password";
            passwordToggleBtn.textContent = isHidden ? "🙈" : "👁";
        });
    }

});

</script>

</body>
</html>


