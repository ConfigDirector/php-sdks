<?php

declare(strict_types=1);

namespace ConfigDirector;

/**
 * Thrown by {@see ConfigDirectorClient::initialize()} when the client cannot start, such as an
 * offline client whose values file is missing or does not hold a JSON object. The client stays as
 * it was: not ready when it had no values, or serving the values it had.
 */
final class ConfigDirectorInitializationException extends ConfigDirectorException {}
