<?php

namespace FabioTech\LaravelElasticEmail\Exception;

use Symfony\Component\Mailer\Exception\TransportException;

final class MessageRejectedException extends TransportException {}
