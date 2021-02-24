<?php

interface SocketEvent
{

   public function incomingConnection(Socket $sock, Socket $new);

   public function connected(AbstractSocket $sock);

   public function sendProgress(AbstractSocket $sock, int $sent, int $remaining);

   public function dataArrival(AbstractSocket $sock, string $data);

   public function socketClosed(AbstractSocket $sock);

   public function socketError(AbstractSocket $sock, $error);

}