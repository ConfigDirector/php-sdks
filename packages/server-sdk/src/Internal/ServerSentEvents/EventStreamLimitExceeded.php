<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\ServerSentEvents;

use ConfigDirector\ConfigDirectorException;

final class EventStreamLimitExceeded extends ConfigDirectorException {}
