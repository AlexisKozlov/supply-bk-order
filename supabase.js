import { createClient } from 'https://cdn.jsdelivr.net/npm/@supabase/supabase-js/+esm';

const SUPABASE_URL = 'https://obywcpilionribalfrbl.supabase.co';

// Правильный anon public key из Supabase Dashboard
const SUPABASE_KEY = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Im9ieXdjcGlsaW9ucmliYWxmcmJsIiwicm9sZSI6ImFub24iLCJpYXQiOjE3Mzc3MjQ5NTcsImV4cCI6MjA1MzMwMDk1N30.G3XgJRBHQB5rVSVVOUo0qsqVHXH2sC3c4PB9LVXgbzA';

export const supabase = createClient(
  SUPABASE_URL,
  SUPABASE_KEY
);