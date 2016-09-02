<?php

//Generates a password salt
function GenerateSalt(){
	return uniqid(mt_rand(), true);
}

//Hashes the given password and salt the number of iterations. Also uses the
//whole-site salt (called pepper), as defined in config.
//There is a minimum and maximum number of iterations for security and performance
//reasons, set to 100 < iterations < 100k. We suggest that passwords and session IDs
//are hashed at least 10k times
//TODO: Move min and max iterations to config
//TODO: Replace die() with in-page warning
function HashPassword($password, $salt, $iterations){
	global $config;
	$pepper = isset($config["PEPPER"]) ? $config["PEPPER"] : "";
	$pswrd = $pepper.$password.$salt;
	
	//Check that we have sufficient iterations for password generation.
	if($iterations < 100){
		die("Insufficient iterations for password generation.");
	}else if($iterations > 100000){
		die("Too many iterations for password generation.");
	}
	
	for($i = 0; $i < $iterations; $i++){
		$pswrd = hash("sha256", $pswrd);
	}
	return $pswrd;
}

//(Re)Loads the users into the globally accessible $users variable.
function LoadUsers(){
	global $users, $dictionary;
	$users = json_decode(file_get_contents("data/users.json"), true);
	foreach($users as $username => $user){
		$users[$username]["username"] = $username;
	}
	ksort($users);
	$dictionary["users"] = $users;
	$dictionary["admins"] = Array();
	$dictionary["registered_users"] = Array();
	foreach($users as $i => $user){
	
		if(isset($user["admin"]) && $user["admin"] != 0){
			$dictionary["admins"][] = $user;
		}else{
			$dictionary["registered_users"][] = $user;
		}
	}
	
}

//Function called when the login form is sent. Either logs in or registers the
//user, depending on whether the username exists. Dies if username exists and the
//password is incorrect.
//TODO: Replace die() with in-page warning
function LogInOrRegister($username, $password){
	global $config, $users;
	
	$username = strtolower(trim($username));
	$password = trim($password);
	
	//Check username length
	if(strlen($username) < 2 || strlen($username) > 20){
		die("username must be between 2 and 20 characters");
	}
	
	//Check password length
	if(strlen($password) < 8){
		die("password must be at least 8 characters long");
	}
	
	//Check password length
	if(strlen($password) > 128){
		die("Okay, okay... okay... No! That's long enough! 128 character max password length is enough! Please, you're making me cry! ;_;");
	}
	
	if(isset($users[$username])){
		//User is registered already, log them in
		LogInUser($username, $password);
	}else{
		//User not yet registered, register now.
		RegisterUser($username, $password);
	}
}

//Registers the given user. Funciton should be called through LogInOrRegister(...).
//Dies if user already exists.
//Calls LogInUser(...) after registering the user to also log them in.
//TODO: Replace die() with in-page warning
function RegisterUser($username, $password){
	global $users;
	
	$username = strtolower(trim($username));
	$password = trim($password);
	
	//Check username length
	if(strlen($username) < 2 || strlen($username) > 20){
		die("username must be between 2 and 20 characters");
	}
	
	//Check password length
	if(strlen($password) < 8 || strlen($password) > 20){
		die("password must be between 8 and 20 characters");
	}
	
	$userSalt = GenerateSalt();
	$userPasswordIterations = intval(rand(10000, 20000));
	$passwordHash = HashPassword($password, $userSalt, $userPasswordIterations);
	
	if(isset($users[$username])){
		die("Username already registered");
	}else{
		$newUser = Array();
		$newUser["salt"] = $userSalt;
		$newUser["password_hash"] = $passwordHash;
		$newUser["password_iterations"] = $userPasswordIterations;
		$newUser["admin"] = 0;
		if(count($users) == 0){
			//If this is the very first user being registered, set them up as an admin.
			$newUser["admin"] = 1;
		}
		
		$users[$username] = $newUser;
	}
	
	file_put_contents("data/users.json", json_encode($users));
	LoadUsers();
	LogInUser($username, $password);
}

//Logs in the user with the provided credentials.
//Sets the user's session cookie.
//Should not be called directly, call through LogInOrRegister(...)
//Dies if user does not exist or the password is incorrect
//TODO: Replace die() with in-page warning
function LogInUser($username, $password){
	global $config, $users;
	
	$username = strtolower(trim($username));
	$password = trim($password);
	
	//Check username length
	if(strlen($username) < 2 || strlen($username) > 20){
		die("username must be between 2 and 20 characters");
	}
	
	//Check password length
	if(strlen($password) < 8 || strlen($password) > 20){
		die("password must be between 8 and 20 characters");
	}
	
	if(!isset($users[$username])){
		die("User does not exist");
	}
	
	$user = $users[$username];
	$correctPasswordHash = $user["password_hash"];
	$userSalt = $user["salt"];
	$userPasswordIterations = intval($user["password_iterations"]);
	$passwordHash = HashPassword($password, $userSalt, $userPasswordIterations);
	if($correctPasswordHash == $passwordHash){
		//User password correct!
		$sessionID = "".GenerateSalt();
		$pepper = isset($config["PEPPER"]) ? $config["PEPPER"] : "BetterThanNothing";
		$sessionIDHash = HashPassword($sessionID, $pepper, $config["SESSION_PASSWORD_ITERATIONS"]);
		
		setcookie("sessionID", $sessionID, time()+60*60*24*30);
		$_COOKIE["sessionID"] = $sessionID;
		
		$sessions = Array();
		if(file_exists("data/sessions.json")){
			$sessions = json_decode(file_get_contents("data/sessions.json"), true);
		}
		
		$sessions[$sessionIDHash]["username"] = $username;
		$sessions[$sessionIDHash]["datetime"] = time();
		
		file_put_contents("data/sessions.json", json_encode($sessions));
		
	}else{
		//User password incorrect!
		die("Incorrect username / password combination.");
	}
}

//Logs out the current user by setting their sessionID cookie to blank and expiring it.
//TODO: Clear session from on-server session data
function LogOut(){
	setcookie("sessionID", "", time());
	$_COOKIE["sessionID"] = "";
}

//Checks whether the current user, identified by the sessionID in their cookies, is
//logged in. This function only actually performs the check the first time it is called.
//after then it caches the result in the global $loggedInUser variable and simply
//returns that. This is to prevent re-hashing the provided sessionID multiple times.
//To force it to re-check, set the global variable $loginChecked to false.
//Returns either the logged in user's username or FALSE if not logged in.
function IsLoggedIn(){
	global $loginChecked, $loggedInUser, $config, $users, $dictionary;
	
	if($loginChecked){
		return $loggedInUser;
	}
	
	if(!isset($_COOKIE["sessionID"])){
		//No session cookie, therefore not logged in
		$loggedInUser = false;
		$loginChecked = true;
		return false;
	}
	
	if(!file_exists("data/sessions.json")){
		//No session was ever created on the site
		$loggedInUser = false;
		$loginChecked = true;
		return false;
	}
	
	$sessions = json_decode(file_get_contents("data/sessions.json"), true);
	$sessionID = "".$_COOKIE["sessionID"];
	$pepper = isset($config["PEPPER"]) ? $config["PEPPER"] : "BetterThanNothing";
	$sessionIDHash = HashPassword($sessionID, $pepper, $config["SESSION_PASSWORD_ITERATIONS"]);
	
	if(!isset($sessions[$sessionIDHash])){
		//Session ID does not exist
		$loggedInUser = false;
		$loginChecked = true;
		return false;
	}else{
		//Session ID does in fact exist
		$username = $sessions[$sessionIDHash]["username"];
		$loggedInUser = $users[$username];
		$loggedInUser["username"] = $username;
		$dictionary["user"] = $loggedInUser;
		$dictionary["user"]["username"] = $username;
		if($loggedInUser["admin"] != 0){
			$dictionary["user"]["isadmin"] = 1;
		}
		$loginChecked = true;
		return $loggedInUser;
	}
}

//Returns TRUE or FALSE depending on whether the logged in user is an admin.
//returns FALSE if there is no logged in user.
//Admins are set by changing adding the "admin":1 parameter to the user's
//object in users.json 
function IsAdmin(){
	global $adminList;
	$loggedInUser = IsLoggedIn();
	if($loggedInUser === false){
		return false;
	}
	
	if($loggedInUser["admin"] != 0){
		return true;
	}else{
		return false;
	}
}



//Edits an existing user, identified by the username.
//Valid values for isAdmin are 0 (not admin) and 1 (admin)
//Only changes whether the user is an admin, does NOT change the user's username.
function EditUser($username, $isAdmin){
	global $users;
	
	//Authorize user (is admin)
	if(IsAdmin() === false){
		die("Only admins can edit entries.");
	}
	
	//Validate values
	if($isAdmin == 0){
		$isAdmin = 0;
	}else if($isAdmin == 1){
		$isAdmin = 1;
	}else{
		die("Bad isadmin value");
		return;
	}
	
	//Check that the user exists
	if(!isset($users[$username])){
		die("User does not exist");
		return;
	}
	
	if($isAdmin == 0){
		$users[$username]["admin"] = "0";
	}else{
		$users[$username]["admin"] = "1";
	}
	
	file_put_contents("data/users.json", json_encode($users));
}

//Changes data about the logged in user
function ChangeUserData($displayName, $twitterHandle){
	global $users, $loggedInUser;
	
	$loggedInUser = IsLoggedIn();
	
	//Authorize user (is admin)
	if($loggedInUser === false){
		die("Not logged in.");
	}
	
	//Validate values
	if(!$displayName || strlen($displayName) <= 0 || strlen($displayName) > 50){
		
	}
	
	//Check that the user exists
	if(!isset($users[$username])){
		die("User does not exist");
		return;
	}
	
	if($isAdmin == 0){
		$users[$username]["admin"] = "0";
	}else{
		$users[$username]["admin"] = "1";
	}
	
	file_put_contents("data/users.json", json_encode($users));
}

//Changes the logged in user's password if the old one matches.
function ChangePassword($oldPassword, $newPassword1, $newPassword2){
	global $users, $loggedInUser;
	
	$loggedInUser = IsLoggedIn();
	
	//Authorize user (is admin)
	if($loggedInUser === false){
		die("Not logged in.");
	}
	
	$newPassword1 = trim($newPassword1);
	$newPassword2 = trim($newPassword2);
	if($newPassword1 != $newPassword2){
		die("passwords don't match");
	}
	$password = $newPassword1;
	
	//Check password length
	if(strlen($password) < 8){
		die("password must be longer than 8 characters");
	}
	
	//Check that the user exists
	if(!isset($users[$loggedInUser["username"]])){
		die("User does not exist");
		return;
	}
	
	$user = $users[$loggedInUser["username"]];
	$correctPasswordHash = $user["password_hash"];
	$userSalt = $user["salt"];
	$userPasswordIterations = intval($user["password_iterations"]);
	$passwordHash = HashPassword($oldPassword, $userSalt, $userPasswordIterations);
	if($correctPasswordHash != $passwordHash){
		die("The entered password is incorrect.");
	}
	
	//Generate new salt, number of iterations and hashed password.
	$newUserSalt = GenerateSalt();
	$newUserPasswordIterations = intval(rand(10000, 20000));
	$newPasswordHash = HashPassword($password, $newUserSalt, $newUserPasswordIterations);
	
	$users[$loggedInUser["username"]]["salt"] = $newUserSalt;
	$users[$loggedInUser["username"]]["password_hash"] = $newPasswordHash;
	$users[$loggedInUser["username"]]["password_iterations"] = $newUserPasswordIterations;
	
	file_put_contents("data/users.json", json_encode($users));
}

//Edits an existing user's password, user is identified by the username.
function EditUserPassword($username, $newPassword1, $newPassword2){
	global $users;
	
	//Authorize user (is admin)
	if(IsAdmin() === false){
		die("Only admins can edit entries.");
	}
	
	$newPassword1 = trim($newPassword1);
	$newPassword2 = trim($newPassword2);
	if($newPassword1 != $newPassword2){
		die("passwords don't match");
	}
	$password = $newPassword1;
	
	//Check password length
	if(strlen($password) < 8){
		die("password must be longer than 8 characters");
	}
	
	//Check that the user exists
	if(!isset($users[$username])){
		die("User does not exist");
		return;
	}
	
	//Generate new salt, number of iterations and hashed password.
	$userSalt = GenerateSalt();
	$userPasswordIterations = intval(rand(10000, 20000));
	$passwordHash = HashPassword($password, $userSalt, $userPasswordIterations);
	
	$users[$username]["salt"] = $userSalt;
	$users[$username]["password_hash"] = $passwordHash;
	$users[$username]["password_iterations"] = $userPasswordIterations;
	
	file_put_contents("data/users.json", json_encode($users));
}




?>