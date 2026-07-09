<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: *");

include 'conexao.php';

$id = rand(1, 999);
$nome = $_POST["nome"];
$email = $_POST["email"];
$comentario = $_POST["comentario"];

$stmt = $link->prepare("INSERT INTO mensagens(id, nome, email, comentario) VALUES (?, ?, ?, ?)");
$stmt->bind_param("isss", $id, $nome, $email, $comentario);

if ($stmt->execute()) {
  echo "New record created successfully";
} else {
  echo "Error: " . $stmt->error;
}

$stmt->close();
?>