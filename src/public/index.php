<?php
$year = date('Y');
?>
<!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Inicio</title>
</head>
<body>
	<header>
		<h1>Bienvenido</h1>
	</header>
	<main>
		<p>Esta es una página básica creada con PHP y HTML.</p>
	</main>
	<footer>
		<small>&copy; <?= htmlspecialchars((string) $year, ENT_QUOTES, 'UTF-8') ?></small>
	</footer>
</body>
</html>
