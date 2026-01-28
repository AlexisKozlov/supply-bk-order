import { createClient } from 'https://cdn.jsdelivr.net/npm/@supabase/supabase-js/+esm';

const SUPABASE_URL = 'https://obywcpilionribalfrbl.supabase.co';
const SUPABASE_KEY = 'sb_publishable_BYToHeprZE-e64UjDgjlmQ_bKZBUFJ0';

export const supabase = createClient(
  SUPABASE_URL,
  SUPABASE_KEY
);
