CREATE TABLE client (
  client_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  name VARCHAR(45) NOT NULL,
  surname VARCHAR(45),
  tax_id VARCHAR(45),
  representative VARCHAR(45),
  registration_time TIMESTAMP NOT NULL,
  is_legal_entity BOOLEAN NOT NULL
);

CREATE TABLE phone (
  phone_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  client_id INT NOT NULL,
  number VARCHAR(20) NOT NULL,
  FOREIGN KEY (client_id) REFERENCES client(client_id)
);

CREATE TABLE email (
  email_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  client_id INT NOT NULL,
  address VARCHAR(100) NOT NULL,
  FOREIGN KEY (client_id) REFERENCES client(client_id)
);

CREATE TABLE address (
  address_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  client_id INT NOT NULL,
  city VARCHAR(45),
  street VARCHAR(45),
  building VARCHAR(10),
  FOREIGN KEY (client_id) REFERENCES client(client_id)
);

CREATE TABLE promotion (
  promotion_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  client_id INT NOT NULL,
  name VARCHAR(45) NOT NULL,
  cost INT NOT NULL,
  start_time TIMESTAMP NOT NULL,
  duration INT NOT NULL,
  FOREIGN KEY (client_id) REFERENCES client(client_id)
);

CREATE TABLE promotion_youtube (
  youtube_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  promotion_id INT NOT NULL,
  channel VARCHAR(45),
  video_length INT,
  skip_after INT,
  FOREIGN KEY (promotion_id) REFERENCES promotion(promotion_id)
);

CREATE TABLE promotion_newspaper (
  newspaper_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  promotion_id INT NOT NULL,
  publication VARCHAR(45),
  page INT,
  size VARCHAR(45),
  FOREIGN KEY (promotion_id) REFERENCES promotion(promotion_id)
);

CREATE TABLE promotion_billboard (
  billboard_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  promotion_id INT NOT NULL,
  location VARCHAR(100),
  size VARCHAR(45),
  illuminated BOOLEAN,
  FOREIGN KEY (promotion_id) REFERENCES promotion(promotion_id)
);

CREATE TABLE promotion_radio (
  radio_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  promotion_id INT NOT NULL,
  station VARCHAR(45),
  air_time TIMESTAMP,
  spot_length INT,
  FOREIGN KEY (promotion_id) REFERENCES promotion(promotion_id)
);

CREATE TABLE promotion_social_media (
  social_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  promotion_id INT NOT NULL,
  platform VARCHAR(45),
  format VARCHAR(45),
  target_age VARCHAR(10),
  FOREIGN KEY (promotion_id) REFERENCES promotion(promotion_id)
);

CREATE TABLE promotion_email_campaign (
  email_campaign_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  promotion_id INT NOT NULL,
  template VARCHAR(100),
  open_rate NUMERIC(5,2),
  FOREIGN KEY (promotion_id) REFERENCES promotion(promotion_id)
);

CREATE TABLE promotion_result (
  result_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  promotion_id INT NOT NULL,
  views INT,
  clicks INT,
  conversions INT,
  recorded_at TIMESTAMP NOT NULL,
  FOREIGN KEY (promotion_id) REFERENCES promotion(promotion_id)
);