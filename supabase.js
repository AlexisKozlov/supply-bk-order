import { createClient } from 'https://cdn.jsdelivr.net/npm/@supabase/supabase-js/+esm';

const SUPABASE_URL = 'https://obywcpilionribalfrbl.supabase.co';
const SUPABASE_KEY = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Im9ieXdjcGlsaW9ucmliYWxmcmJsIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NjkxNjQ4NzYsImV4cCI6MjA4NDc0MDg3Nn0.gOo7_prJfR7kxw6Fe8ZrEEIbhEK02DCNYPr9Ln2EuYc';

export const supabase = createClient(SUPABASE_URL, SUPABASE_KEY);