-- количество распределенных сделок у пользователей за сутки
CREATE TABLE leads_counter (
    id INT (6) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    date date DEFAULT CURRENT_TIMESTAMP,  -- здесь храним дату DDMMYYYY
    id_manager INT (6) NOT NULL,
    counter INT (6) DEFAULT 0
)

-- справочник групп
CREATE TABLE groups (
    id INT (6) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR (50) NOT NULL DEFAULT '',
    load_percent float DEFAULT 0  -- процент загрузки отдела. задается в настройках
)

-- справочник менеджеров
CREATE TABLE managers(
    id INT (6) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name varchar(50) NOT NULL DEFAULT '',
	group_id int not null, -- ссылка на поле id - таблицы справочника групп
    load_percent_by_group float not null,  -- процент загрузки менеджера внутри отдела. задается в настройках
    load_percent float not null,  -- процент загрузки менеджера относительно всех менеджеров. величина расчитывается в момент сохранения настроек
	is_active boolean
)
