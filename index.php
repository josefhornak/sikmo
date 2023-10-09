<?php

use Slim\Factory\AppFactory;
use Slim\Factory\ServerRequestCreatorFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as Capsule;
use SIKMO\Models\UserModel;
use SIKMO\Models\RoleModel;
use SIKMO\Models\UserRoleModel;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require __DIR__ . '/vendor/autoload.php';

// Create a ServerRequest creator
$serverRequestCreator = ServerRequestCreatorFactory::create();

// Create a PSR-7 Request object from the global environment (usually $_SERVER)
$request = $serverRequestCreator->createServerRequestFromGlobals();

// Inicializace Capsule (Eloquent ORM)
$capsule = new Capsule;
$capsule->addConnection(require __DIR__ . '/config/database.php');
$capsule->setAsGlobal();
$capsule->bootEloquent();

$app = AppFactory::create();

// Inicializace rendereru pro šablony Twig
$twig = Twig::create(__DIR__ . '/templates', ['cache' => false]);

// Přiřazení Twig jako template engine pro Slim
$app->add(new TwigMiddleware($twig, $app->getRouteCollector()->getRouteParser()));

// Přihlašovací stránka
$app->get('/login', function (Request $request, Response $response, $args) use ($twig) {
    return $twig->render($response, 'login.twig', $args);
});

// Zpracování přihlášení
$app->post('/login', function (Request $request, Response $response, $args) use ($twig) {
    // Zde provedete ověření přihlašovacích údajů, například v SQL databázi
    // Použijete Eloquent ORM pro vyhledání uživatele
    $username = $request->getParsedBody()['username'];
    $password = $request->getParsedBody()['password'];

    // Hledej uživatele v databázi podle jména
    $user = UserModel::where('username', $username)->first();

    if ($user && password_verify($password, $user->password)) {
        // Uživatel byl nalezen a heslo je platné
        // Zde provedete další akce po úspěšném přihlášení
        return $response->withHeader('Location', '/user_list')->withStatus(302);
    } else {
        // Uživatel nebyl nalezen nebo heslo je neplatné
        // Zobrazte chybu nebo přesměrujte zpět na přihlašovací stránku
        return $twig->render($response, 'login.twig', ['error' => 'Neplatné přihlašovací údaje']);
    }
});

$app->get('/user_list', function (Request $request, Response $response, $args) use ($twig) {
    $users = UserModel::all();

    return $twig->render($response, 'user_list.twig', [
        'users' => $users,
    ]);
});


// Route for displaying the add user form
$app->get('/add_user', function (Request $request, Response $response) use ($twig) {
    $roles = RoleModel::all();

    return $twig->render($response, 'add_user.twig', ['roles' => $roles]);
});




$app->get('/user_detail/{UserID}', function (Request $request, Response $response, $args) use ($twig) {
    $userID = $args['UserID'];

    // Fetch the user from the database based on $userID
    $user = UserModel::find($userID);

    if (!$user) {
        return $response->withHeader('Location', '/user_list')->withStatus(302);
    }

    // Fetch the roles assigned to the user from the UserRoles table
    $userRoles = UserRoleModel::where('UserID', $userID)->get();

    // Fetch the list of all available roles
    $roles = RoleModel::all();

    $userData = [
        'user' => $user,
        'userRoles' => $userRoles, // Pass the roles assigned to the user
        'roles' => $roles, // Pass the available roles to the template
    ];

    return $twig->render($response, 'user_detail.twig', $userData);
});



// Route for deleting a user using a stored procedure
$app->get('/delete_user/{UserID}', function (Request $request, Response $response, $args) {
    $userID = $args['UserID'];

    global $capsule;

    $sql = "{CALL DeleteUser (?)}";

    try {
        $pdo = $capsule->getConnection()->getPdo();
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(1, $userID, PDO::PARAM_INT);
        $stmt->execute();

        $redirectUrl = '/user_list?delete=success';
        return $response->withHeader('Location', $redirectUrl)->withStatus(302);
    } catch (PDOException $e) {
        return $response->withStatus(500)->withJson(['error' => $e->getMessage()]);
    }
});


$app->post('/update_user/{UserID}', function (Request $request, Response $response, $args) {
    $userID = $args['UserID'];
    $formData = $request->getParsedBody();

    global $capsule;

    $sql = "{CALL UpdateUser (?, ?, ?, ?, ?, ?)}";

    $surname = $formData['surname'];
    $firstname = $formData['firstname'];    
    $username = $formData['username'];
    $email = $formData['email'];
    $role = $formData['role'];

    try {
        $pdo = $capsule->getConnection()->getPdo();
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(1, $userID, PDO::PARAM_INT);
        $stmt->bindParam(2, $surname, PDO::PARAM_STR);
        $stmt->bindParam(3, $firstname, PDO::PARAM_STR);
        $stmt->bindParam(4, $username, PDO::PARAM_STR);
        $stmt->bindParam(5, $email, PDO::PARAM_STR);
        $stmt->bindParam(6, $role, PDO::PARAM_STR);

        $stmt->execute();

        $redirectUrl = '/user_list?update=success';
        return $response->withHeader('Location', $redirectUrl)->withStatus(302);
    } catch (PDOException $e) {
        return $response->withStatus(500)->withJson(['error' => $e->getMessage()]);
    }
});

$app->post('/add_user', function (Request $request, Response $response) {
    $formData = $request->getParsedBody();

    global $capsule;

    $sql = "{CALL AddUser (?, ?, ?, ?, ?, ?)}";

    // Extract form data
    $username = $formData['username'];
    $surname = $formData['surname'];
    $firstname = $formData['firstname'];
    $email = $formData['email'];
    $password = password_hash($formData['password'], PASSWORD_DEFAULT); // Hash the password
    $role = $formData['role'];

    try {
        // Execute the stored procedure
        $pdo = $capsule->getConnection()->getPdo();
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(1, $username, PDO::PARAM_STR);
        $stmt->bindParam(2, $surname, PDO::PARAM_STR);
        $stmt->bindParam(3, $firstname, PDO::PARAM_STR);
        $stmt->bindParam(4, $email, PDO::PARAM_STR);
        $stmt->bindParam(5, $password, PDO::PARAM_STR);
        $stmt->bindParam(6, $role, PDO::PARAM_INT);

        $stmt->execute();

        $redirectUrl = '/user_list?update=success';
        return $response->withHeader('Location', $redirectUrl)->withStatus(302);
    } catch (PDOException $e) {
        return $response->withStatus(500)->withJson(['error' => $e->getMessage()]);
    }
});



// Redirect root URL to /login
$app->get('/', function (Request $request, Response $response, $args) {
    return $response->withHeader('Location', '/login')->withStatus(302);
});

$app->run();
