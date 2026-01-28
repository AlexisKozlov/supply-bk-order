import { createClient } from 'https://cdn.jsdelivr.net/npm/@supabase/supabase-js/+esm';

const SUPABASE_URL = 'https://obywcpilionribalfrbl.supabase.co';
const SUPABASE_KEY = 'sb_secret_boMa397rtT4H9Oty8VE-rA_Z2OwqfMW';

export const supabase = createClient(
  SUPABASE_URL,
  SUPABASE_KEY
);
