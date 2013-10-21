<?php

namespace LibBER;

use LibASN1\Type,
    LibASN1\Boolean,
    LibASN1\Integer;

interface OutputStream
{
    public function write($data);
}
