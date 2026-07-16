-- Seed data for Mayhem Mobility

USE mayhem_mobility;

INSERT INTO mechanics (name, bio, nickname, quote, theme, specialties, years_experience) VALUES
('Clark Kent',
 'A mild-mannered guy who somehow gets every job done before anyone else even finishes their coffee. Claims it''s "just good technique." Nobody''s buying it.',
 'The Big Guy',
 'I''ll have you back on the road before you can say "vroom!"',
 'clark',
 'everything, heavy lifting, engine rebuilds, impossible deadlines',
 20),
('Diana Prince',
 'Former military transport officer with an almost supernatural ability to diagnose electrical gremlins from across the room. Her tools are always immaculate. She never raises her voice. She never has to.',
 'The Princess',
 'Your car won''t know what hit it. Pure precision, zero drama.',
 'diana',
 'electrical systems, diagnostics, european imports, wiring',
 14),
('Jay Garrick',
 'A blur in the shop. Oil changes in under 4 minutes. Tire rotations before you can blink. Rumor has it he once rebuilt a carburetor during a coffee break.',
 'The Flash',
 'Blink and you''ll miss it. I''m that fast. Your car will thank you later.',
 'jay',
 'quick service, tune-ups, carburetors, brake systems',
 11),
('Rex Tyler',
 'A man who lives by the clock. Gives every job exactly one hour — no more, no less. Somehow it always works. His station has three different stopwatches bolted to the bench.',
 'The Hourman',
 'Sixty minutes. Not a second more. I don''t waste time, and neither should your car.',
 'rex',
 'precision work, timing belts, engine tuning, diesel engines',
 16),
('Dinah Drake',
 'A former singer who claims she can "hear" what''s wrong with an engine just by listening. Oddly, she''s never wrong. Specializes in transmissions and engines that "sound off."',
 'The Canary',
 'I can hear a cracked manifold from three bays away. Your secret''s safe with me.',
 'dinah',
 'transmissions, engine acoustics, american muscle, exhaust',
 12);

-- Mon-Fri, all 4 slots
INSERT INTO mechanic_schedule (mechanic_id, day_of_week, slot_1, slot_2, slot_3, slot_4)
SELECT m.id, dow.n, TRUE, TRUE, TRUE, TRUE
FROM mechanics m
CROSS JOIN (
    SELECT 1 AS n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5
) dow
WHERE m.is_active = TRUE;

-- Clark works Saturdays too (half day)
INSERT INTO mechanic_schedule (mechanic_id, day_of_week, slot_1, slot_2, slot_3, slot_4)
SELECT id, 6, TRUE, TRUE, TRUE, FALSE
FROM mechanics
WHERE name LIKE 'Clark%';

-- Diana also works Saturdays (slots 1-2 only — she has plans)
INSERT INTO mechanic_schedule (mechanic_id, day_of_week, slot_1, slot_2, slot_3, slot_4)
SELECT id, 6, TRUE, TRUE, FALSE, FALSE
FROM mechanics
WHERE name LIKE 'Diana%';
