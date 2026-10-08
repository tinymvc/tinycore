<?php

return new class {
    public function up(): void
    {
        query('users')->insert(['name' => 'John Doe', 'email' => 'admin@mail.com']);
    }

    public function down(): void
    {
        query('users')->where('email', 'admin@mail.com')->delete();
    }
};
