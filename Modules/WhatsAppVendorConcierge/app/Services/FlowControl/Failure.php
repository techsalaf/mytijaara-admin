<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\FlowControl;

/** Only server-authored operational explanations may cross the admin boundary. */
final class Failure extends \InvalidArgumentException {}
