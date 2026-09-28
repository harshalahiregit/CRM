// The live server the app talks to by default. A driver never has to type this;
// it is pre-filled and works after any local machine is off, because it is the
// real production server, not a laptop.
//
// It stays editable on the login/register screens for testing against another
// server, but this is the one that ships.
export const DEFAULT_SERVER = 'https://crm.nexforeconsulting.com'
