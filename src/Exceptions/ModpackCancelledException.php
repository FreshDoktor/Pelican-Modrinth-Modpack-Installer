<?php

namespace CedricKassen\MinecraftModpackModrinth\Exceptions;

use Exception;

/**
 * Thrown inside a job to stop it once the user cancelled its operation.
 */
class ModpackCancelledException extends Exception {}
